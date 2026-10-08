<?php

namespace App\Services\Pipeline;

use App\Enums\PipelineStatus;
use App\Models\DocTypeRule;
use App\Models\FieldPattern;
use App\Models\LlmCall;
use App\Models\ProcessedEmail;
use App\Models\ValidationPrompt;
use App\Models\ValidationRule;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cliente HTTP de los dos workers Python.
 *
 * Un solo cliente para ambos stacks a propósito: el fallback de CU-05
 * (OpenRouter caído →Stack A) es simplemente reintentar la misma llamada
 * contra la otra URL, sin cambiar el código que la invoca.
 */
class AnalyzerClient
{
    /** Marca el `detail` de las etapas que corrieron en modo degradado. */
    public bool $degraded = false;

    public function __construct(private readonly string $stack = 'a')
    {
    }

    // ── Configuración enviada al worker ──────────────────────────────────────

    /** Reglas de clasificación activas (Stack A). */
    private function docTypeRules(): array
    {
        return DocTypeRule::query()
            ->where('active', true)
            ->get()
            ->map(fn (DocTypeRule $r) => $r->toWireFormat())
            ->all();
    }

    /** Regex de extracción activas (Stack A). */
    private function fieldPatterns(): array
    {
        return FieldPattern::activeMap();
    }

    /** Reglas de Fase 2 activas (Stack A). */
    private function validationRules(): array
    {
        return ValidationRule::query()
            ->where('active', true)
            ->get()
            ->map(fn (ValidationRule $r) => $r->toWireFormat())
            ->all();
    }

    /** Prompts versionados activos (Stack B). */
    private function prompts(): array
    {
        return ValidationPrompt::activeMap();
    }

    // ── Llamadas ─────────────────────────────────────────────────────────────

    /**
     * `POST /analyze` — detección de tipo, texto, clasificación y campos.
     *
     * @return array{ok: bool, data: array, error: ?string, degraded: bool}
     */
    public function analyze(ProcessedEmail $email, int $attachmentId): array
    {
        $attachment = $email->attachments()->where('id', $attachmentId)->firstOrFail();

        return $this->call(
            email: $email,
            attachmentId: $attachmentId,
            endpoint: '/analyze',
            payload: [
                'attachment_id'  => $attachment->uuid,
                'path'           => $attachment->local_path,
                'filename'       => $attachment->filename,
                'doc_type_rules' => $this->docTypeRules(),
                'field_patterns' => $this->fieldPatterns(),
                'prompts'        => $this->stack() === 'b' ? $this->prompts() : [],
            ],
        );
    }

    /**
     * `POST /validate` — Fase 2.
     *
     * @param  array<string, mixed>  $facts
     * @return array{ok: bool, data: array, error: ?string, degraded: bool}
     */
    public function validate(
        ProcessedEmail $email,
        ?int $attachmentId,
        array $facts
    ): array {
        return $this->call(
            email: $email,
            attachmentId: $attachmentId,
            endpoint: '/validate',
            payload: [
                'email_id'      => $email->uuid,
                'attachment_id' => $attachmentId ? $email->attachments()->find($attachmentId)?->uuid : null,
                'doc_type'      => $facts['doc_type'] ?? 'desconocido',
                'facts'         => $facts,
                'rules'         => $this->stack() === 'a' ? $this->validationRules() : [],
                'prompts'       => $this->stack() === 'b' ? $this->prompts() : [],
                'today'         => now()->toDateString(),
            ],
        );
    }

    /**
     * `POST /generate-reply` — redacta la respuesta cuando hay issues.
     *
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, data: array, error: ?string, degraded: bool}
     */
    public function generateReply(ProcessedEmail $email, array $payload): array
    {
        return $this->call(
            email: $email,
            attachmentId: null,
            endpoint: '/generate-reply',
            payload: $payload,
        );
    }

    // ── Transporte ───────────────────────────────────────────────────────────

    /**
     * Hace la llamada con failover: si el stack principal (b) falla por red o
     * por 5xx, reintenta contra Stack A y marca la degradación (CU-05).
     */
    private function call(
        ProcessedEmail $email,
        ?int $attachmentId,
        string $endpoint,
        array $payload
    ): array {
        $primary = $this->stack();

        $response = $this->send($primary, $endpoint, $payload);

        if ($response['ok']) {
            $this->persistTraces($email, $attachmentId, $response['data'], $primary, degraded: false);

            return $response;
        }

        // Solo Stack B tiene fallback: si A falla no hay a dónde caer.
        // Y solo se cae a A cuando el fallo es de transporte o del propio
        // worker (5xx, timeout, conexión): un 4xx significa que el payload
        // está mal, así que A recibiría exactamente lo mismo y fallaría igual,
        // solo que más lento (CU-05).
        if ($primary !== 'b' || ! $this->isWorthRetrying($response['error'])) {
            $this->persistTraces(
                $email,
                $attachmentId,
                $response['data'],
                $primary,
                degraded: false,
                error: $response['error'],
            );

            return $response;
        }

        Log::warning('Stack B falló, degradando a Stack A', [
            'endpoint' => $endpoint,
            'email_id' => $email->id,
            'error'    => $response['error'],
        ]);

        $fallback = $this->send('a', $endpoint, $payload);
        $this->degraded = true;

        // Se persiste la traza del fallo de B con `degraded = true`: es la
        // evidencia de que hubo failover (CU-05, métrica llm_failover_total).
        $this->persistTraces(
            $email,
            $attachmentId,
            $response['data'],
            'b',
            degraded: true,
            error: $response['error'],
        );

        if ($fallback['ok']) {
            $this->persistTraces($email, $attachmentId, $fallback['data'], 'a', degraded: false);
        }

        return $fallback;
    }

    /**
     * ¿Vale la pena reintentar contra Stack A?
     *
     * Un 4xx (o un 422 por payload inválido) es un error de la REQUEST: A
     * recibiría el mismo body y devolvería lo mismo, así que la cascada solo
     * costaría tiempo. Un 5xx, un timeout o un problema de conexión sí es del
     * worker, y ahí sí conviene degradar (CU-05).
     */
    private function isWorthRetrying(?string $error): bool
    {
        if ($error === null) {
            return false;
        }

        // "No se pudo conectar con …" — `ConnectionException` de Http.
        if (str_starts_with($error, 'No se pudo conectar')) {
            return true;
        }

        preg_match('/HTTP (\d{3})/', $error, $matches);

        if ($matches === []) {
            // Sin código HTTP recognition (p. ej. cuerpo de error raro): se
            // asume recuperable, porque degradar a A no puede empeorar nada.
            return true;
        }

        return (int) $matches[1] >= 500;
    }

    /**
     * @return array{ok: bool, data: array, error: ?string}
     */
    private function send(string $stack, string $endpoint, array $payload): array
    {
        $config = config("gmail_docs.workers.$stack");
        $url = $config['url'].$endpoint;

        try {
            $response = Http::withToken((string) config('gmail_docs.token'))
                ->timeout((int) config('gmail_docs.timeout'))
                ->acceptJson()
                ->post($url, $payload);
        } catch (ConnectionException $e) {
            return [
                'ok'    => false,
                'data'  => [],
                'error' => "No se pudo conectar con $stack en $url: ".$e->getMessage(),
            ];
        }

        $data = $response->json() ?? [];

        // 5xx = el worker está caído o degradado → vale la pena caer a Stack A.
        // 4xx no: un 422 significa que el payload está mal y reintentar igual
        // fallaría, solo que más lento.
        if ($response->successful()) {
            return ['ok' => true, 'data' => $data, 'error' => null];
        }

        $detail = $data['detail'] ?? $data['error'] ?? $response->body();

        return [
            'ok'    => false,
            'data'  => $data,
            'error' => "HTTP {$response->status()} desde $stack: "
                .\Illuminate\Support\Str::limit((string) $detail, 400),
        ];
    }

    /**
     * Persiste las trazas `_llm_traces` que devuelve Stack B (RNF-04).
     *
     * Cuando la llamada falló no hay `_llm_traces` que leer — el worker no
     * llegó a responder—, pero el intento sigue siendo evidencia auditable de
     * que hubo failover, así que se escribe una fila mínima con el error.
     *
     * @param  array<string, mixed>  $data
     */
    private function persistTraces(
        ProcessedEmail $email,
        ?int $attachmentId,
        array $data,
        string $stack,
        bool $degraded,
        ?string $error = null,
    ): void {
        if ($stack !== 'b') {
            return;
        }

        $traces = $data['_llm_traces'] ?? [];

        if (! is_array($traces) || $traces === []) {
            if ($degraded || $error !== null) {
                LlmCall::query()->create([
                    'email_id'      => $email->id,
                    'attachment_id' => $attachmentId,
                    'purpose'       => 'worker_call',
                    'model'         => (string) config('gmail_docs.workers.b.label', 'stack-b'),
                    'latency_ms'    => 0,
                    'cache_hit'     => false,
                    'degraded'      => $degraded,
                    'error'         => $error,
                ]);
            }

            return;
        }

        foreach ($traces as $trace) {
            if (! is_array($trace)) {
                continue;
            }

            LlmCall::query()->create([
                'email_id'          => $email->id,
                'attachment_id'     => $attachmentId,
                'purpose'           => (string) ($trace['purpose'] ?? 'desconocido'),
                'model'             => (string) ($trace['model'] ?? ''),
                'prompt_tokens'     => (int) ($trace['prompt_tokens'] ?? 0),
                'completion_tokens' => (int) ($trace['completion_tokens'] ?? 0),
                'cost_usd'          => (float) ($trace['cost_usd'] ?? 0),
                'latency_ms'        => (int) ($trace['latency_ms'] ?? 0),
                'cache_hit'         => (bool) ($trace['cache_hit'] ?? false),
                'degraded'          => $degraded || (bool) ($trace['error'] ?? false),
                'raw_request'       => $this->truncate($trace['raw_request'] ?? null, 60_000),
                'raw_response'      => $this->truncate($trace['raw_response'] ?? null, 60_000),
                'parsed_response'   => $this->truncate($trace['parsed_response'] ?? null, 60_000),
                'error'             => $trace['error'] ?? null,
            ]);
        }
    }

    /**
     * Recorta campos gigantes. Una llamada con un PDF en base64 puede dejar el
     * request en varios MB, que no sirve para auditoría ni para la BD.
     */
    private function truncate(mixed $value, int $limit): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        return \Illuminate\Support\Str::limit($value, $limit);
    }

    // ── Estado ───────────────────────────────────────────────────────────────

    public function stack(): string
    {
        return $this->stack;
    }

    public function useStack(string $stack): void
    {
        $this->stack = $stack;
    }

    /** Comprueba que los dos workers estén respondiendo (RNF-07). */
    public static function health(): array
    {
        $out = [];

        foreach (['a', 'b'] as $stack) {
            $url = config("gmail_docs.workers.$stack.url").'/healthz';

            try {
                $response = Http::acceptJson()->timeout(5)->get($url);
                $out[$stack] = $response->successful()
                    ? ['up' => true, 'detail' => $response->json()]
                    : ['up' => false, 'detail' => "HTTP {$response->status()}"];
            } catch (ConnectionException $e) {
                $out[$stack] = ['up' => false, 'detail' => $e->getMessage()];
            }
        }

        return $out;
    }
}
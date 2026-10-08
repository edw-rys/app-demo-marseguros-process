<?php

namespace App\Services\Pipeline;

use App\Enums\PipelineStage;
use App\Enums\PipelineStatus;
use App\Models\AnalysisCache;
use App\Models\Attachment;
use App\Models\EmailResponse;
use App\Models\ProcessedEmail;
use App\Models\ValidationResult;
use App\Services\Attachments\AttachmentDownloader;
use App\Services\Gmail\GmailReplySender;
use App\Support\DocumentTypes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * El pipeline completo de un correo: descarga, análisis por adjunto,
 * validación en dos fases y respuesta.
 *
 * Cada etapa se ejecuta dentro de `StageRecorder::run()`, así que el timeline
 * del admin se arma solo. Cuando una etapa lanza, se corta el pipeline, se
 * marcan las siguientes como `skipped` y se responde al cliente con lo que se
 * sepa hasta ese punto.
 */
class EmailPipeline
{
    private StageRecorder $recorder;

    private AnalyzerClient $client;

    public function __construct(
        private readonly ProcessedEmail $email,
        private readonly ?AttachmentDownloader $downloader = null,
    ) {
        $this->recorder = new StageRecorder($email, $email->pipeline_stack);
        $this->client = new AnalyzerClient($email->pipeline_stack);
    }

    /**
     * Ejecuta el pipeline completo.
     *
     * Nunca lanza: cualquier fallo queda registrado como etapa `failed` y el
     * correo termina en un estado terminal con su respuesta. Un watcher no debe
     * morir por un correo raro.
     */
    public function run(): ProcessedEmail
    {
        $this->email->forceFill(['status' => ProcessedEmail::STATUS_PROCESSING])->save();

        try {
            $this->recorder->mark(
                PipelineStage::Received,
                PipelineStatus::Passed,
                detail: [
                    'gmail_id'    => $this->email->gmail_id,
                    'mailbox'     => $this->email->mailbox,
                    'subject'     => $this->email->subject,
                    'received_at' => $this->email->received_at?->toIso8601String(),
                ],
                message: 'Correo detectado en el buzón.',
            );

            $attachments = $this->downloadStage();
            [$attachments, $unreadable] = $this->analyzeAttachments($attachments);

            // Un adjunto que no se pudo ni leer inhibits validar el expediente:
            // responderle al cliente "faltan documentos" cuando en realidad los
            // envió pero ilegibles lo haría reenviar un archivo que ya existe.
            if ($unreadable !== []) {
                [$status, $summary] = [ProcessedEmail::STATUS_FAILED, $this->unreadableSummary($unreadable)];
            } else {
                [$status, $summary] = $this->validateStage($attachments);
            }

            $this->replyStage($status, $summary);

            $this->email->forceFill([
                'status'        => $status,
                'current_stage' => PipelineStage::Done->value,
            ])->save();

            $this->recorder->mark(
                PipelineStage::Done,
                PipelineStatus::Passed,
                detail: ['status' => $status],
                message: "Pipeline finalizado con estado '{$status}'.",
            );
        } catch (\Throwable $e) {
            Log::error('Pipeline abortado', [
                'email_id' => $this->email->id,
                'error'    => $e->getMessage(),
            ]);

            $this->email->forceFill([
                'status' => ProcessedEmail::STATUS_FAILED,
            ])->save();

            $this->recorder->markRemainingAsSkipped($this->currentStage());
        }

        return $this->email->refresh();
    }

    // ── Etapas ────────────────────────────────────────────────────────────────

    /** RF-02 · descarga de adjuntos. */
    private function downloadStage(): array
    {
        return $this->recorder->run(
            PipelineStage::Download,
            function (): array {
                $downloader = $this->downloader ?? app(AttachmentDownloader::class);

                $result = $downloader->downloadFor($this->email);

                return [
                    'downloaded' => count($result['saved']),
                    'warnings'   => $result['warnings'],
                    'filenames'  => $result['saved'],
                ];
            },
            detail: ['mailbox' => $this->email->mailbox],
        );
    }

    /**
     * Etapas por adjunto: `detect_kind` → `extract_text` → `classify` →
     * `extract_fields`. Un adjunto fallido no tumba el correo entero; se marca
     * y el resto del pipeline sigue con los que sí salieron bien.
     *
     * @return array{0: Collection<int, Attachment>, 1: array<int, array{filename: string, error: string}>}
     */
    private function analyzeAttachments(array $downloaded): array
    {
        $attachments = $this->email->attachments()->get();
        $analyzed = [];
        $unreadable = [];

        foreach ($attachments as $attachment) {
            try {
                $analyzed[] = $this->analyzeOneAttachment($attachment);
            } catch (\Throwable $e) {
                // El adjunto queda registrado en `attachments.processing_error`
                // para que el admin muestre qué pasó con cada archivo.
                $attachment->forceFill(['processing_error' => $e->getMessage()])->save();

                // Y las etapas que nunca corrieron se marcan `skipped`, para
                // que el timeline no tenga huecos.
                $this->recorder->markAttachmentSkipped(PipelineStage::DetectKind, $attachment->id);

                $unreadable[] = [
                    'filename' => $attachment->filename,
                    'error'    => $e->getMessage(),
                ];

                Log::warning('Adjunto no analizable', [
                    'attachment_id' => $attachment->id,
                    'error'         => $e->getMessage(),
                ]);
            }
        }

        return [collect(array_values(array_filter($analyzed))), $unreadable];
    }

    /**
     * Resumen de issues cuando hubo adjuntos ilegibles (CU-04).
     *
     * @param  array<int, array{filename: string, error: string}>  $unreadable
     * @return array<string, mixed>
     */
    private function unreadableSummary(array $unreadable): array
    {
        $issues = [];

        foreach ($unreadable as $item) {
            $issues[] = [
                'code'     => 'ARCHIVO_ILEGIBLE',
                'severity' => 'error',
                'filename' => $item['filename'],
                'message'  => $item['error'],
            ];

            ValidationResult::query()->create([
                'email_id'   => $this->email->id,
                'rule_name'  => 'adjuntos_ilegibles',
                'status'     => 'error',
                'severity'   => 'error',
                'code'       => 'ARCHIVO_ILEGIBLE',
                'message'    => "{$item['filename']}: {$item['error']}",
                'facts_json' => ['filename' => $item['filename'], 'error' => $item['error']],
            ]);
        }

        return [
            'issues'       => $issues,
            'missing'      => [],
            'unclassified' => array_column($unreadable, 'filename'),
        ];
    }

    private function analyzeOneAttachment(Attachment $attachment): Attachment
    {
        // F-12: si el mismo archivo ya se analizó con este stack, se reutiliza.
        $cached = AnalysisCache::recall($attachment->sha256, $this->email->pipeline_stack);
        if ($cached !== null) {
            $attachment->forceFill($this->fieldsFromAnalysis($cached))->save();

            $this->recorder->mark(
                PipelineStage::Classify,
                PipelineStatus::Passed,
                detail: ['cache_hit' => true, 'doc_type' => $attachment->doc_type],
                message: "Cache por hash: doc_type='{$attachment->doc_type}'.",
                attachmentId: $attachment->id,
            );

            return $attachment;
        }

        // Ambos casos de fallo se lanzan DENTRO del closure: si se lanzaran fuera,
        // `StageRecorder` cerraría la etapa como `passed` y el admin mostraría
        // un `detect_kind` en verde sobre un archivo que no se pudo leer.
        $result = $this->recorder->run(
            PipelineStage::DetectKind,
            function () use ($attachment): array {
                $result = $this->client->analyze($this->email, $attachment->id);

                if (! $result['ok']) {
                    // Fallo de transporte: el worker no está o devolvió 5xx.
                    throw new \RuntimeException($result['error'] ?? 'Fallo en detect_kind');
                }

                return $result;
            },
            detail: [
                'filename'         => $attachment->filename,
                'sha256'           => $attachment->sha256,
                'size_bytes'       => $attachment->size_bytes,
                'declared_kind'    => pathinfo($attachment->local_path, PATHINFO_EXTENSION),
            ],
            attachmentId: $attachment->id,
        );

        $data = $result['data'] ?? [];

        // Si el worker indica un tipo no soportado o ignorado (ej. .heic, .zip, .exe, etc.):
        // El archivo se marca como ignorado sin tumbar el correo ni lanzar error fatal.
        if (! empty($data['error'])) {
            $detectedKind = $data['detected_kind'] ?? strtolower(pathinfo($attachment->filename, PATHINFO_EXTENSION));
            $attachment->forceFill([
                'detected_kind'    => $detectedKind,
                'doc_type'         => 'ignorado',
                'extracted_text'   => null,
                'processing_error' => null,
            ])->save();

            $this->recorder->mark(
                PipelineStage::ExtractText,
                PipelineStatus::Skipped,
                detail: ['reason' => 'Formato de archivo ignorado', 'kind' => $detectedKind],
                message: 'Etapa omitida: formato de archivo ignorado.',
                attachmentId: $attachment->id,
            );

            $this->recorder->mark(
                PipelineStage::Classify,
                PipelineStatus::Skipped,
                detail: ['doc_type' => 'ignorado', 'kind' => $detectedKind],
                message: 'Etapa omitida: archivo ignorado.',
                attachmentId: $attachment->id,
            );

            $this->recorder->mark(
                PipelineStage::ExtractFields,
                PipelineStatus::Skipped,
                detail: [],
                message: 'Etapa omitida: archivo ignorado.',
                attachmentId: $attachment->id,
            );

            return $attachment;
        }

        $attachment->forceFill($this->fieldsFromAnalysis($data))->save();

        AnalysisCache::remember(
            $attachment->sha256,
            $this->email->pipeline_stack,
            $data,
        );

        // Las sub-etapas que el worker hizo de un tirón quedan registradas
        // explícitamente: el admin debe poder mostrar qué corrió y qué no.
        $this->recorder->mark(
            PipelineStage::ExtractText,
            PipelineStatus::Passed,
            detail: [
                'detected_kind'  => $attachment->detected_kind,
                'ocr_used'       => $attachment->ocr_used,
                'ocr_engine'     => $attachment->ocr_engine,
                'ocr_confidence' => $attachment->ocr_confidence,
                'text_chars'     => mb_strlen((string) $attachment->extracted_text),
            ],
            message: $attachment->ocr_used
                ? "Texto vía OCR ({$attachment->ocr_engine}), confianza {$attachment->ocr_confidence}%."
                : 'Texto extraído sin OCR.',
            attachmentId: $attachment->id,
        );

        $this->recorder->mark(
            PipelineStage::Classify,
            PipelineStatus::Passed,
            detail: [
                'doc_type'    => $attachment->doc_type,
                'confidence'  => $attachment->confidence,
                'reasoning'   => $attachment->reasoning,
                'key_signals' => $attachment->key_signals,
            ],
            message: "Clasificado como '{$attachment->doc_type}' "
                .'(confianza '.($attachment->confidence ?? 0).').',
            attachmentId: $attachment->id,
        );

        $fieldCount = is_array($attachment->fields_json) ? count($attachment->fields_json) : 0;

        $this->recorder->mark(
            PipelineStage::ExtractFields,
            PipelineStatus::Passed,
            detail: ['fields_count' => $fieldCount],
            message: "{$fieldCount} campo(s) extraído(s).",
            attachmentId: $attachment->id,
        );

        return $attachment;
    }

    /**
     * Fases 1 y 2 (RF-08 A / RF-07 A y RF-08 B).
     *
     * @return array{0: string, 1: array} estado final y resumen de issues
     */
    private function validateStage(Collection $attachments): array
    {
        // ── Fase 1: conteo de documentos requeridos ─────────────────────────
        [$status, $summary] = $this->recorder->run(
            PipelineStage::Phase1Count,
            fn () => $this->phase1($attachments),
            detail: [
                'adjuntos'  => count($attachments),
                'clasificados' => $attachments
                    ->mapWithKeys(fn (Attachment $a) => [$a->filename => $a->doc_type])
                    ->all(),
            ],
        );

        // Si Fase 1 detecta documentos faltantes, no tiene sentido validar el
        // contenido de lo que no está: se responde pidiendo los faltantes y el
        // job queda `pending` esperando el reenvío (CU-02).
        //
        // `phase1()` devuelve `pending` cuando falta algo y `processing` cuando
        // el expediente está completo (que es el caso en que sí se sigue).
        if ($status === ProcessedEmail::STATUS_PENDING) {
            return [$status, $summary];
        }

        // ── Fase 2: reglas / prompts ────────────────────────────────────────
        return $this->recorder->run(
            PipelineStage::Phase2Validate,
            fn () => $this->phase2($attachments),
            detail: ['documentos_a_validar' => count($attachments)],
        );
    }

    /**
     * Fase 1 — verifica que estén todos los documentos del corredor.
     *
     * @return array{0: string, 1: array{issues: array, missing: array, unclassified: array}}
     */
    private function phase1(Collection $attachments): array
    {
        $corridor = $this->corridor();
        $required = config("gmail_docs.required_documents.$corridor")
            ?? config('gmail_docs.required_documents.'.config('gmail_docs.default_corridor'));

        $counts = [];
        foreach ($attachments as $attachment) {
            $type = $attachment->doc_type ?: 'desconocido';
            $counts[$type] = ($counts[$type] ?? 0) + 1;
        }

        $missing = array_values(array_filter(
            $required,
            fn (string $docType) => ($counts[$docType] ?? 0) < 1,
        ));

        $unclassified = $attachments
            ->filter(fn (Attachment $a) => ! $a->doc_type || $a->doc_type === 'desconocido')
            ->pluck('filename')
            ->all();

        $summary = [
            'issues'       => [],
            'required'     => $required,
            'missing'      => $missing,
            'unclassified' => $unclassified,
            'counts'       => $counts,
            'corridor'     => $corridor,
        ];

        ValidationResult::query()->create([
            'email_id'   => $this->email->id,
            'rule_name'  => 'fase1_'.$corridor,
            'status'     => $missing ? 'fail' : 'pass',
            'severity'   => $missing ? 'error' : 'info',
            'code'       => $missing ? 'FALTAN_DOCUMENTOS' : 'DOCUMENTOS_COMPLETOS',
            'message'    => $missing
                // Títulos, no keys: «Faltan: Solicitud, Identificación (INE)».
                // La key sola no le dice nada a quien atiende el correo, y esta
                // fila es lo primero que va a leer.
                ? 'Faltan: '.implode(', ', DocumentTypes::labels($missing))
                : 'Todos los documentos requeridos están presentes.',
            'facts_json' => $summary,
        ]);

        if ($missing) {
            $summary['issues'][] = [
                'code'    => 'FALTAN_DOCUMENTOS',
                'message' => 'Falta adjuntar: '.implode(', ', $missing).'.',
            ];
        }

        // Falta un documento → `pending`: se espera el reenvío (CU-02).
        return [$missing
            ? ProcessedEmail::STATUS_PENDING
            : ProcessedEmail::STATUS_PROCESSING, $summary];
    }

    /**
     * Fase 2 — ejecuta reglas (Stack A) o prompts (Stack B) sobre cada adjunto.
     *
     * @return array{0: string, 1: array}
     */
    private function phase2(Collection $attachments): array
    {
        $summary = ['issues' => [], 'missing' => [], 'unclassified' => []];
        $approved = true;

        foreach ($attachments as $attachment) {
            // Un documento sin clasificar no se valida: no sabemos qué reglas
            // aplicarle. Ya se reporta como issue en Fase 1.
            if (! $attachment->doc_type || $attachment->doc_type === 'desconocido') {
                continue;
            }

            $facts = ($attachment->fields_json ?? []) + [
                'doc_type' => $attachment->doc_type,
                'filename' => $attachment->filename,
            ];

            $result = $this->client->validate($this->email, $attachment->id, $facts);

            if (! $result['ok']) {
                $this->recordFailure($attachment, $result['error'] ?? 'Validación falló');
                $summary['issues'][] = [
                    'code'    => 'VALIDACION_FALLIDA',
                    'message' => $result['error'] ?? 'No se pudo validar el documento.',
                ];
                $approved = false;
                continue;
            }

            $this->persistValidationResults($attachment, $result['data']);

            foreach ($result['data']['results'] ?? [] as $item) {
                if (! in_array($item['status'] ?? '', ['fail', 'warn', 'error'], true)) {
                    continue;
                }

                $summary['issues'][] = [
                    'code'     => $item['code'] ?? $item['rule_name'] ?? 'SIN_CODIGO',
                    'severity' => $item['severity'] ?? 'error',
                    'message'  => $item['message'] ?? '',
                    'filename' => $attachment->filename,
                ];

                if (($item['severity'] ?? 'error') === 'error') {
                    $approved = false;
                }
            }
        }

        $summary['approved'] = $approved;

        $status = match (true) {
            $approved => ProcessedEmail::STATUS_VALIDATED,
            default   => ProcessedEmail::STATUS_FAILED,
        };

        return [$status, $summary];
    }

    /**
     * Guarda los resultados de Fase 2 como filas de `validation_results`.
     *
     * Se guarda también el `facts` evaluado: permite re-ejecutar la validación
     * sin volver a extraer el documento (RNF-04).
     *
     * @param  array<string, mixed>  $data
     */
    private function persistValidationResults(Attachment $attachment, array $data): void
    {
        ValidationResult::query()->where('email_id', $this->email->id)
            ->where('attachment_id', $attachment->id)
            ->delete();

        foreach ($data['results'] ?? [] as $item) {
            ValidationResult::query()->create([
                'email_id'      => $this->email->id,
                'attachment_id' => $attachment->id,
                'rule_name'     => (string) ($item['rule_name'] ?? 'desconocida'),
                'status'        => (string) ($item['status'] ?? 'pass'),
                'severity'      => (string) ($item['severity'] ?? 'error'),
                'code'          => $item['code'] ?? null,
                'message'       => $item['message'] ?? null,
                'facts_json'    => $attachment->fields_json,
            ]);
        }
    }

    private function recordFailure(Attachment $attachment, string $error): void
    {
        ValidationResult::query()->create([
            'email_id'      => $this->email->id,
            'attachment_id' => $attachment->id,
            'rule_name'     => 'worker_validation',
            'status'        => 'error',
            'severity'      => 'warning',
            'code'          => 'WORKER_ERROR',
            'message'       => mb_substr($error, 0, 1000),
        ]);
    }

    // ── Respuesta (RF-09) ────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $summary
     */
    private function replyStage(string $status, array $summary): void
    {
        $this->recorder->run(
            PipelineStage::Reply,
            fn () => $this->sendReply($status, $summary),
            detail: [
                'status'    => $status,
                'issues'    => $summary['issues'] ?? [],
                'missing'   => $summary['missing'] ?? [],
            ],
        );
    }

    /** @param array<string, mixed> $summary */
    private function sendReply(string $status, array $summary): array
    {
        $unclassified = $summary['unclassified'] ?? [];
        $issues = $summary['issues'] ?? [];
        $missing = $summary['missing'] ?? [];

        $source = 'template';
        $template = match (true) {
            $status === ProcessedEmail::STATUS_VALIDATED => 'validated',
            $missing !== []                                => 'missing',
            $status === ProcessedEmail::STATUS_REVIEW && $unclassified !== [] => 'clarify',
            $issues !== []                                 => 'issues',
            default                                        => 'unknown',
        };

        if ($template === 'validated' || $template === 'missing') {
            // RF-09: estos dos casos NO llaman al LLM — un template fijo basta
            // y ahorra tokens (CU-02).
            $body = $template === 'validated'
                ? (string) config('gmail_docs.reply.validated')
                : str_replace(
                    '{detalle}',
                    implode("\n", array_map(
                        fn (string $doc) => '  - '.$doc,
                        $missing,
                    )),
                    (string) config('gmail_docs.reply.missing'),
                );
        } else {
            // Con errores o sin clasificar, el LLM redacta (solo en Stack B;
            // Stack A compone el mismo texto con plantillas).
            $response = $this->client->generateReply($this->email, [
                'status'            => $status,
                'sender_name'       => $this->email->sender_name,
                'issues'            => $issues,
                'missing_documents' => $missing,
                'unclassified'      => $unclassified,
            ]);

            if ($response['ok'] && ! empty($response['data']['body'])) {
                $body = (string) $response['data']['body'];
                $source = (string) ($response['data']['source'] ?? 'llm');
            } else {
                // Si el LLM tampoco respondió, se usa el texto genérico: es
                // preferible un correo poor a ninguno.
                $body = $template === 'clarify'
                    ? 'Recibimos su correo, pero no pudimos identificar algunos '
                        .'archivos adjuntos. ¿Podría confirmar qué documento es '
                        .'cada uno y reenviarlo en PDF si es posible?'
                    : (string) config('gmail_docs.reply.unknown');
            }
        }

        // RF-09: respuesta en el hilo original, no un correo nuevo.
        $sent = false;
        $error = null;

        if (config('gmail_docs.send_enabled') && $this->email->thread_id) {
            try {
                app(GmailReplySender::class)->send($this->email, $body);
                $sent = true;
            } catch (\Throwable $e) {
                $error = $e->getMessage();
                Log::warning('No se pudo enviar la respuesta', [
                    'email_id' => $this->email->id,
                    'error'    => $error,
                ]);
            }
        }

        EmailResponse::query()->create([
            'email_id' => $this->email->id,
            'template' => $template,
            'source'   => $source,
            'body'     => $body,
            'sent_at'  => $sent ? now() : null,
            // Sin `send_enabled` la respuesta se guarda pero no se manda: es el
            // modo demo, y queda visible en el admin igual.
            'status'   => $sent ? 'sent' : (config('gmail_docs.send_enabled') ? 'failed' : 'dry_run'),
            'error'    => $error,
        ]);

        return [
            'template' => $template,
            'source'   => $source,
            'sent'     => $sent,
            'chars'    => mb_strlen($body),
        ];
    }

    // ── Auxiliares ───────────────────────────────────────────────────────────

    /**
     * Traduce la respuesta de `/analyze` a columnas de `attachments`.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function fieldsFromAnalysis(array $data): array
    {
        return [
            'detected_kind'     => $data['detected_kind'] ?? null,
            'mime'              => $data['mime'] ?? null,
            'extension_mismatch' => (bool) ($data['extension_mismatch'] ?? false),
            'doc_type'          => $data['doc_type'] ?? 'desconocido',
            'confidence'        => $data['confidence'] ?? null,
            'reasoning'         => $data['reasoning'] ?? null,
            'key_signals'       => $data['key_signals'] ?? null,
            'score_breakdown'   => $data['score_breakdown'] ?? null,
            'fields_json'       => $data['fields'] ?? null,
            'text_path'         => $data['text_path'] ?? null,
            'extracted_text'    => $data['text'] ?? null,
            'ocr_used'          => (bool) ($data['ocr_used'] ?? false),
            'ocr_engine'        => $data['ocr_engine'] ?? null,
            'ocr_confidence'    => $data['ocr_confidence'] ?? null,
            'processing_error'  => $data['error'] ?? null,
        ];
    }

    /**
     * Deduce el corredor del trámite a partir del asunto.
     *
     * En v1 es un mapeo por palabras clave en `gmail_docs.corridor_by_subject`.
     * Se comparan sin acentos ni mayúsculas para que "póliza" y "POLIZA"
     * caigan en el mismo corredor.
     */
    private function corridor(): string
    {
        $subject = $this->normalize((string) $this->email->subject);

        foreach (config('gmail_docs.corridor_by_subject') as $corridor => $hints) {
            foreach ($hints as $hint) {
                if (\Illuminate\Support\Str::contains($subject, $this->normalize($hint))) {
                    return $corridor;
                }
            }
        }

        return (string) config('gmail_docs.default_corridor');
    }

    /** Minúsculas y sin diacríticos. */
    private function normalize(string $text): string
    {
        return \Illuminate\Support\Str::of($text)
            ->lower()
            ->ascii()
            ->toString();
    }

    /** Última etapa registrada, para saber dónde se cortó el pipeline. */
    private function currentStage(): PipelineStage
    {
        $last = $this->email->stages()
            ->whereIn('status', [PipelineStatus::Running->value, PipelineStatus::Failed->value])
            ->orderByDesc('sequence')
            ->first();

        return $last?->stageEnum() ?? PipelineStage::Received;
    }
}
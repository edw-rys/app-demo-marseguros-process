<?php

namespace Tests\Feature;

use App\Enums\PipelineStage;
use App\Models\Attachment;
use App\Models\AnalysisCache;
use App\Models\DocTypeRule;
use App\Models\EmailResponse;
use App\Models\JobStage;
use App\Models\LlmCall;
use App\Models\ProcessedEmail;
use App\Models\ValidationResult;
use App\Services\Pipeline\AnalyzerClient;
use App\Services\Pipeline\EmailPipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Tests del pipeline completo (RF-01, CU-02, CU-04, CU-05).
 *
 * Los dos workers Python se falsean con `Http::fake()`: lo que se prueba aquí
 * es la orquestación de etapas de Laravel, no el OCR ni el LLM, que tienen sus
 * propios tests en `stack-a/` y `stack-b/`.
 */
class PipelineTest extends TestCase
{
    use RefreshDatabase;

    // ── Utilidades ───────────────────────────────────────────────────────────

    /** URLs de los dos workers, tal como las resuelve `config/gmail_docs`. */
    private function urlA(string $endpoint): string
    {
        return config('gmail_docs.workers.a.url').$endpoint;
    }

    private function urlB(string $endpoint): string
    {
        return config('gmail_docs.workers.b.url').$endpoint;
    }

    /**
     * Falsa LOS SEIS endpoints (2 stacks × 3 rutas).
     *
     * Es deliberado: `Http::fake([...])` responde con un 200 vacío a cualquier
     * URL no declarada, y eso enmascararía justo lo que estos tests comprueban
     * — que la caída de B provoca el fallback a A y no un "todo fue bien".
     * Un endpoint sin cuerpo declarado responde 500, así que una llamada
     * inesperada se ve como un fallo y no como un acierto silencioso.
     *
     * @param  array<string, array>  $responses  endpoint => cuerpo de respuesta (implícito 200)
     * @param  array<string, int>    $statuses   endpoint => código HTTP explícito
     */
    private function fakeWorkers(array $responses = [], array $statuses = []): void
    {
        $stubs = [];

        foreach (['A' => $this->urlA(''), 'B' => $this->urlB('')] as $stack => $base) {
            foreach (['/analyze', '/validate', '/generate-reply'] as $endpoint) {
                $key = $stack.' '.$endpoint;

                $stubs[$base.$endpoint] = Http::response(
                    $responses[$key] ?? [],
                    $statuses[$key] ?? (array_key_exists($key, $responses) ? 200 : 500),
                );
            }
        }

        Http::fake($stubs);
    }

    /** Respuesta de éxito de `/analyze` con un documento ya clasificado. */
    private function analyzeOk(string $docType = 'factura'): array
    {
        return [
            'detected_kind'      => 'pdf',
            'extension_mismatch' => false,
            'text'               => 'FACTURA #001-001-0000001. RUC 1799999999001.',
            'ocr_used'           => false,
            'doc_type'           => $docType,
            'confidence'         => 0.92,
            'fields'             => ['numero_factura' => '001-001-0000001', 'monto_total' => '1234.56'],
            'elapsed_ms'         => 312,
        ];
    }

    /** `/validate` sin findings: el documento pasa. */
    private function validateOk(): array
    {
        return [
            'results'  => [['rule_name' => 'cualquiera', 'status' => 'pass', 'severity' => 'info']],
            'approved' => true,
        ];
    }

    /** `/generate-reply` del worker B. */
    private function replyOk(string $body = 'Gracias por su envío.'): array
    {
        return ['body' => $body, 'source' => 'llm'];
    }

    /** Etapas ejecutadas, en el orden en que se registraron. */
    private function stageKeys(ProcessedEmail $email): array
    {
        return JobStage::query()
            ->where('email_id', $email->id)
            ->orderBy('sequence')
            ->pluck('stage_key')
            ->all();
    }

    // ── Camino feliz ─────────────────────────────────────────────────────────

    public function test_pipeline_completo_deja_el_job_validado_y_responde(): void
    {
        $this->fakeWorkers([
            'A /analyze' => $this->analyzeOk(),
            'A /validate' => $this->validateOk(),
        ]);

        $email = ProcessedEmail::factory()
            ->withSubject('Trámite: Factura de agosto')
            ->forStack('a')
            ->create();

        Attachment::factory()->forEmail($email)->create();

        $result = app(EmailPipeline::class, ['email' => $email])->run();

        $this->assertSame(ProcessedEmail::STATUS_VALIDATED, $result->status);
        $this->assertSame(PipelineStage::Done->value, $result->current_stage);

        // La respuesta se guarda siempre; sin `send_enabled` queda en dry_run.
        $response = EmailResponse::query()->sole();
        $this->assertSame('validated', $response->template);
        $this->assertSame('dry_run', $response->status);
        $this->assertNull($response->sent_at);
    }

    public function test_registra_una_fila_por_etapa_con_duracion(): void
    {
        $this->fakeWorkers([
            'A /analyze' => $this->analyzeOk(),
            'A /validate' => $this->validateOk(),
        ]);

        $email = ProcessedEmail::factory()
            ->withSubject('Trámite: Factura de agosto')
            ->forStack('a')
            ->create();
        Attachment::factory()->forEmail($email)->create();

        app(EmailPipeline::class, ['email' => $email])->run();

        $stages = JobStage::query()->where('email_id', $email->id)->get();

        // Hitos + las 4 etapas por adjunto + fase1 + fase2 + reply, en orden.
        $this->assertSame(
            [
                'received', 'download',
                'detect_kind', 'extract_text', 'classify', 'extract_fields',
                'phase1_count', 'phase2_validate', 'reply', 'done',
            ],
            $this->stageKeys($email),
        );

        // Ninguna etapa queda en `running`: el timeline del admin lo muestra.
        $this->assertNotContains('running', $stages->pluck('status')->all());
        $this->assertNotContains('failed', $stages->pluck('status')->all());

        // Las etapas que hicieron trabajo real midieron duración.
        $detect = $stages->firstWhere('stage_key', 'detect_kind');
        $this->assertNotNull($detect->started_at);
        $this->assertNotNull($detect->finished_at);
        $this->assertGreaterThanOrEqual(0, (int) $detect->duration_ms);
    }

    public function test_el_timeline_crece_una_fila_por_adjunto(): void
    {
        $this->fakeWorkers([
            'A /analyze' => $this->analyzeOk(),
            'A /validate' => $this->validateOk(),
        ]);

        $email = ProcessedEmail::factory()
            ->withSubject('Trámite: Factura de agosto')
            ->forStack('a')
            ->create();

        Attachment::factory()->count(3)->forEmail($email)->create();

        app(EmailPipeline::class, ['email' => $email])->run();

        // 3 adjuntos × 4 etapas por adjunto.
        $this->assertSame(3, JobStage::query()->where('stage_key', 'detect_kind')->count());
        $this->assertSame(3, JobStage::query()->where('stage_key', 'classify')->count());

        // Las etapas por adjunto apuntan a su adjunto, no quedan huérfanas.
        $this->assertSame(
            3,
            JobStage::query()->where('stage_key', 'detect_kind')->whereNotNull('attachment_id')->count(),
        );
    }

    // ── CU-02: falta un documento ───────────────────────────────────────────

    public function test_falta_un_documento_deja_el_job_pendiente_y_no_llega_a_fase2(): void
    {
        $this->fakeWorkers([
            'A /analyze' => $this->analyzeOk('solicitud'),
            'A /validate' => $this->validateOk(),
        ]);

        // Asunto de póliza nueva → requiere solicitud + ine + comprobante.
        $email = ProcessedEmail::factory()
            ->withSubject('Trámite: Póliza nueva')
            ->forStack('a')
            ->create();

        Attachment::factory()->forEmail($email)->create();

        $result = app(EmailPipeline::class, ['email' => $email])->run();

        $this->assertSame(ProcessedEmail::STATUS_PENDING, $result->status);

        // Fase 2 nunca corrió: no tiene sentido validar lo que no llegó.
        $this->assertSame(0, JobStage::query()->where('stage_key', 'phase2_validate')->count());

        // La respuesta pide lo que falta, nombrándolo (RF-09). Con títulos, no con
        // keys: este texto lo lee la persona que mandó los adjuntos, y «ine» no
        // le dice qué traer.
        $response = EmailResponse::query()->sole();
        $this->assertSame('missing', $response->template);
        $this->assertStringContainsString('Cédula de identidad', $response->body);
        $this->assertStringContainsString('Comprobante de domicilio', $response->body);
        $this->assertStringNotContainsString('comprobante_domicilio', $response->body);

        // Y el faltante queda registrado con su código.
        $result1 = ValidationResult::query()->where('rule_name', 'fase1_poliza_nueva')->sole();
        $this->assertSame('fail', $result1->status);
        $this->assertSame('FALTAN_DOCUMENTOS', $result1->code);
    }

    // ── CU-04: ejecutable renombrado ────────────────────────────────────────

    public function test_un_tipo_no_soportado_se_ignora_sin_tumbar_el_correo(): void
    {
        // El worker responde 200 pero con `error`: es un resultado de negocio,
        // no un fallo de transporte (un `.exe` no es un PDF válido). No es lo
        // mismo que el worker caído, así que no puede cortarse el pipeline:
        // el resto de los adjuntos del correo tiene que seguir su curso.
        $this->fakeWorkers([
            'A /analyze' => [
                'detected_kind'      => 'exe',
                'extension_mismatch' => true,
                'error'              => 'El archivo no es un PDF válido (tipo real: exe).',
            ],
        ]);

        $email = ProcessedEmail::factory()
            ->withSubject('Trámite: Factura de agosto')
            ->forStack('a')
            ->create();
        Attachment::factory()->forEmail($email)->create(['filename' => 'factura.pdf']);

        $result = app(EmailPipeline::class, ['email' => $email])->run();

        // El adjunto se aparta como `ignorado` en vez de contarlo como
        // documento: si no, un `.exe` renombrado a `factura.pdf` aprobaría la
        // Fase 1 por cushonear el conteo.
        $this->assertSame('ignorado', $email->attachments()->sole()->doc_type);

        // Y como no hay factura, el correo queda esperando el reenvío.
        $this->assertSame(ProcessedEmail::STATUS_PENDING, $result->status);

        // `detect_kind` sí corrió — el worker respondió — y las etapas que no
        // tienen sentido sobre un archivo ignorado quedan `skipped`, no
        // ausentes: el admin muestra qué no llegó a ejecutarse.
        $this->assertSame('passed', JobStage::query()->where('stage_key', 'detect_kind')->sole()->status);

        foreach (['extract_text', 'classify', 'extract_fields'] as $key) {
            $this->assertSame(
                'skipped',
                JobStage::query()->where('stage_key', $key)->sole()->status,
                "La etapa {$key} debería quedar skipped.",
            );
        }

        // Fase 2 nunca corrió: no hay nada que validar.
        $this->assertSame(0, JobStage::query()->where('stage_key', 'phase2_validate')->count());
    }

    // ── CU-05: fallback de Stack B a Stack A ────────────────────────────────

    public function test_stack_b_caido_degrada_a_stack_a_y_el_job_termina(): void
    {
        // B responde 503 en todo → vale la pena caer a A (CU-05).
        $this->fakeWorkers(
            [
                'A /analyze' => $this->analyzeOk(),
                'A /validate' => $this->validateOk(),
            ],
            [
                'B /analyze'       => 503,
                'B /validate'      => 503,
                'B /generate-reply' => 503,
            ],
        );

        $email = ProcessedEmail::factory()
            ->withSubject('Trámite: Factura de agosto')
            ->forStack('b')
            ->create();

        Attachment::factory()->forEmail($email)->create();

        $result = app(EmailPipeline::class, ['email' => $email])->run();

        // El job NO se pierde: termina validado con los resultados de A.
        $this->assertSame(ProcessedEmail::STATUS_VALIDATED, $result->status);

        // Y queda constancia del failover: sin esto no se puede auditar.
        $this->assertGreaterThan(
            0,
            LlmCall::query()->where('degraded', true)->count(),
            'El intento fallido de B debe quedar registrado como degradado.',
        );

        // A respondió de verdad: hubo una llamada a su URL.
        Http::assertSent(fn ($r) => $r->url() === $this->urlA('/analyze'));
    }

    public function test_stack_a_caido_no_hay_a_donde_caer_y_el_job_falla(): void
    {
        $this->fakeWorkers([], ['A /analyze' => 500]);

        $email = ProcessedEmail::factory()
            ->withSubject('Trámite: Factura de agosto')
            ->forStack('a')
            ->create();
        Attachment::factory()->forEmail($email)->create();

        $result = app(EmailPipeline::class, ['email' => $email])->run();

        $this->assertSame(ProcessedEmail::STATUS_FAILED, $result->status);
        $this->assertSame(
            'failed',
            JobStage::query()->where('stage_key', 'detect_kind')->sole()->status,
        );

        // Sin fallback no debe haber trazas de LLM: A no es un LLM.
        $this->assertSame(0, LlmCall::query()->count());
    }

    public function test_un_4xx_no_dispara_fallback_porque_reintentar_tampoco_va_a_servir(): void
    {
        // B con 422 (payload malo): A respondería bien, así que si se cayera a A
        // el test lo detectaría. No debe hacerlo.
        $this->fakeWorkers(
            ['A /analyze' => $this->analyzeOk()],
            ['B /analyze' => 422],
        );

        $email = ProcessedEmail::factory()
            ->withSubject('Trámite: Factura de agosto')
            ->forStack('b')
            ->create();
        Attachment::factory()->forEmail($email)->create();

        $result = app(EmailPipeline::class, ['email' => $email])->run();

        $this->assertSame(ProcessedEmail::STATUS_FAILED, $result->status);

        Http::assertNotSent(fn ($r) => $r->url() === $this->urlA('/analyze'));
    }

    // ── F-12: cache por hash ────────────────────────────────────────────────

    public function test_el_segundo_job_con_el_mismo_hash_no_vuelve_a_llamar_al_worker(): void
    {
        $this->fakeWorkers([
            'A /analyze' => $this->analyzeOk(),
            'A /validate' => $this->validateOk(),
        ]);

        $first = ProcessedEmail::factory()
            ->withSubject('Trámite: Factura de agosto')
            ->forStack('a')
            ->create();
        Attachment::factory()->forEmail($first)->create(['sha256' => str_repeat('a', 64)]);

        app(EmailPipeline::class, ['email' => $first])->run();

        $this->assertSame(1, Http::recorded(fn ($r) => str_ends_with($r->url(), '/analyze'))->count());
        $this->assertNotNull(AnalysisCache::query()->where('sha256', str_repeat('a', 64))->first());

        // Segundo correo, MISMO archivo (mismo sha256) en otro trámite.
        $second = ProcessedEmail::factory()
            ->withSubject('Trámite: Factura de agosto')
            ->forStack('a')
            ->create();
        Attachment::factory()->forEmail($second)->create(['sha256' => str_repeat('a', 64)]);

        $result = app(EmailPipeline::class, ['email' => $second])->run();

        // No hubo una segunda llamada a /analyze: se reutilizó el análisis.
        // `Http::assertSentCount` cuenta TODAS las peticiones registradas, no
        // solo las que cumplen el closure, así que aquí se cuenta a mano.
        $analyzeCalls = Http::recorded(fn ($r) => str_ends_with($r->url(), '/analyze'));
        $this->assertCount(1, $analyzeCalls);

        // Pero el documento igual quedó clasificado en el segundo job.
        $this->assertSame(ProcessedEmail::STATUS_VALIDATED, $result->status);
        $this->assertSame('factura', $second->attachments()->sole()->doc_type);

        // Y el timeline lo deja dicho.
        $cached = JobStage::query()
            ->where('email_id', $second->id)
            ->where('stage_key', 'classify')
            ->sole();
        $this->assertTrue((bool) $cached->detail_json['cache_hit']);
    }

    public function test_la_cache_se_ignora_si_el_stack_es_distinto(): void
    {
        // La respuesta de A y la de B no son intercambiables: la extracción de
        // campos de un LLM no es la misma que la de una regex.
        AnalysisCache::remember(str_repeat('b', 64), 'a', $this->analyzeOk());

        $this->fakeWorkers([
            'B /analyze' => $this->analyzeOk('poliza_vigente'),
            'B /validate' => $this->validateOk(),
        ]);

        $email = ProcessedEmail::factory()->forStack('b')->create();
        Attachment::factory()->forEmail($email)->create(['sha256' => str_repeat('b', 64)]);

        app(EmailPipeline::class, ['email' => $email])->run();

        Http::assertSent(fn ($r) => $r->url() === $this->urlB('/analyze'));
        $this->assertSame('poliza_vigente', $email->attachments()->sole()->doc_type);
    }

    // ── Validaciones de Fase 2 ──────────────────────────────────────────────

    public function test_un_hallazgo_con_severidad_error_deja_el_job_con_errores(): void
    {
        $this->fakeWorkers([
            'A /analyze' => $this->analyzeOk(),
            'A /validate' => [
                'results'  => [[
                    'rule_name' => 'factura_vigente',
                    'status'    => 'fail',
                    'severity'  => 'error',
                    'code'      => 'FACTURA_VENCIDA',
                    'message'   => 'La factura tiene más de 90 días.',
                ]],
                'approved' => false,
            ],
            'A /generate-reply' => $this->replyOk('La factura está vencida.'),
        ]);

        $email = ProcessedEmail::factory()
            ->withSubject('Trámite: Factura de agosto')
            ->forStack('a')
            ->create();
        Attachment::factory()->forEmail($email)->create();

        $result = app(EmailPipeline::class, ['email' => $email])->run();

        $this->assertSame(ProcessedEmail::STATUS_FAILED, $result->status);

        $finding = ValidationResult::query()->where('rule_name', 'factura_vigente')->sole();
        $this->assertSame('fail', $finding->status);
        $this->assertSame('FACTURA_VENCIDA', $finding->code);
        $this->assertTrue($email->refresh()->hasErrors());

        // Con errores, la respuesta la redacta el worker, no un template fijo.
        $response = EmailResponse::query()->sole();
        $this->assertSame('llm', $response->source);
        $this->assertSame('La factura está vencida.', $response->body);
    }

    public function test_reprocesar_agrega_una_corrida_nueva_sin_borrar_la_anterior(): void
    {
        $this->fakeWorkers([
            'A /analyze' => $this->analyzeOk(),
            'A /validate' => $this->validateOk(),
        ]);

        $email = ProcessedEmail::factory()
            ->withSubject('Trámite: Factura de agosto')
            ->forStack('a')
            ->create();
        Attachment::factory()->forEmail($email)->create();

        app(EmailPipeline::class, ['email' => $email])->run();
        $afterFirst = JobStage::query()->where('email_id', $email->id)->count();

        AnalysisCache::query()->delete();
        app(EmailPipeline::class, ['email' => $email])->run();

        // Append-only: el timeline anterior sigue ahí, con su auditoría.
        $this->assertGreaterThan($afterFirst, JobStage::query()->where('email_id', $email->id)->count());

        // Y el admin lo lee como dos corridas separadas.
        $runs = \App\Filament\Resources\Jobs\Schemas\JobInfolist::runsOf($email->refresh());
        $this->assertCount(2, $runs);
    }

    /**
     * Con la credencial de solo lectura, intentar enviar tiene que fallar con un
     * mensaje que se pueda seguir — no con el 403 "insufficient authentication
     * scopes" de Google, que no dice nada de cómo arreglarlo.
     *
     * Además el estado tiene que quedar en `failed` y no en `dry_run`: acá sí se
     * intentó enviar, y un operador leyendo el admin tiene que ver el fallo.
     */
    public function test_con_scope_de_solo_lectura_el_envio_falla_con_un_mensaje_util(): void
    {
        config([
            'gmail_docs.send_enabled' => true,
            'gmail_docs.google.auth_mode' => 'oauth',
            'gmail_docs.google.oauth.scopes' => ['https://www.googleapis.com/auth/gmail.readonly'],
        ]);

        $this->fakeWorkers([
            'A /analyze' => $this->analyzeOk(),
            'A /validate' => $this->validateOk(),
        ]);

        $email = ProcessedEmail::factory()
            ->withSubject('Trámite: Factura de agosto')
            ->forStack('a')
            ->create();
        Attachment::factory()->forEmail($email)->create();

        app(EmailPipeline::class, ['email' => $email])->run();

        $response = EmailResponse::query()->sole();
        $this->assertSame('failed', $response->status);
        $this->assertNull($response->sent_at);
        $this->assertStringContainsString('solo lectura', (string) $response->error);
    }

    // ── Seguridad ───────────────────────────────────────────────────────────

    public function test_el_bearer_del_worker_se_envia_en_la_cabecera_y_no_en_la_url(): void
    {
        config(['gmail_docs.token' => 'secreto-de-demo']);

        $this->fakeWorkers([
            'A /analyze' => $this->analyzeOk(),
            'A /validate' => $this->validateOk(),
        ]);

        $email = ProcessedEmail::factory()
            ->withSubject('Trámite: Factura de agosto')
            ->forStack('a')
            ->create();
        Attachment::factory()->forEmail($email)->create();

        app(EmailPipeline::class, ['email' => $email])->run();

        Http::assertSent(function ($request) {
            return $request->hasHeader('Authorization', 'Bearer secreto-de-demo')
                && ! str_contains($request->url(), 'secreto-de-demo');
        });
    }

    public function test_la_config_de_reglas_se_lee_de_la_bd_en_cada_llamada(): void
    {
        // RF-07 A: editar una regla en Filament debe surtir efecto en el
        // siguiente job sin reiniciar nada. Eso exige leerla de la BD por
        // request, no cachearla en el constructor.
        $email = ProcessedEmail::factory()->forStack('a')->create();
        Attachment::factory()->forEmail($email)->create();

        $this->fakeWorkers(['A /analyze' => $this->analyzeOk()]);

        DocTypeRule::query()->create([
            'name'        => 'recibo_de_pago',
            'label'       => 'Recibo de pago',
            'active'      => true,
            'version'     => 1,
            'keywords'    => [],
            'required_keywords' => [],
            'filename_hints'    => [],
        ]);

        (new AnalyzerClient('a'))->analyze($email, $email->attachments()->sole()->id);

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);
            $names = array_column($body['doc_type_rules'] ?? [], 'name');

            return in_array('recibo_de_pago', $names, true);
        });
    }
}
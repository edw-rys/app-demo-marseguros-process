<?php

namespace Tests\Feature;

use App\Filament\Resources\Jobs\Schemas\JobInfolist;
use App\Models\Attachment;
use App\Models\JobStage;
use App\Models\ProcessedEmail;
use App\Models\ValidationResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * El grafo de etapas del detalle del job.
 *
 * Cubre lo que la vista promete, que es distinto de lo que promete el pipeline:
 * que se vea como un pipeline (columnas unidas por conectores), que cada paso
 * diga su estado, que los adjuntos se puedan abrir, y que un fallo quede a la
 * vista. Es una vista, así que el test mira el HTML: un `assertSee` sobre el
 * texto o la clase que el usuario ve es la única forma de que un cambio de
 * markup no rompa nada en silencio.
 *
 * Lo que el paso dice *detrás* del enlace (qué hace la etapa, cómo se descarga
 * el archivo) vive en `StageDetailPageTest`.
 */
class JobTimelineViewTest extends TestCase
{
    use RefreshDatabase;

    private string $storageRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storageRoot = storage_path('app/attachments/test-timeline');
        File::ensureDirectoryExists($this->storageRoot);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storageRoot);

        parent::tearDown();
    }

    // ── Casos ────────────────────────────────────────────────────────────────

    public function test_job_sin_etapas_muestra_el_estado_vacio_y_no_un_pipeline(): void
    {
        $html = $this->renderFor(ProcessedEmail::factory()->create());

        $this->assertStringContainsString('todavía no tiene etapas', $html);
        $this->assertStringNotContainsString('Ejecución #', $html);
        $this->assertStringNotContainsString('gdv-pipeline-step', $html);
    }

    public function test_dibuja_un_paso_por_etapa_del_pipeline_en_orden(): void
    {
        $email = ProcessedEmail::factory()->create();

        foreach (['received', 'subject_filter', 'download', 'phase1_count', 'phase2_validate', 'reply', 'done'] as $i => $key) {
            $this->stage($email, $key, 'passed', sequence: $i);
        }

        $html = $this->renderFor($email);

        foreach (['Recibido', 'Filtro de asunto', 'Descarga de adjuntos', 'Fase 1 · Conteo', 'Fase 2 · Validación', 'Respuesta', 'Finalizado'] as $label) {
            $this->assertStringContainsString($label, $html, "falta el paso «{$label}»");
        }

        // El pipeline siempre muestra las 11 etapas del enum (aunque no hayan
        // corrido), así que 7 en verde es la cuenta de las que sí pasaron.
        $this->assertSame(7, substr_count($html, 'class="gdv-pipeline-step gdv-pipeline-step--passed'));

        // Y las columnas van unidas por conectores: sin ellos es una lista de
        // cajas, no un pipeline.
        $this->assertSame(4, substr_count($html, '<div class="gdv-pipeline-link"></div>'), 'cinco columnas → cuatro conectores');
    }

    public function test_una_etapa_que_no_corrio_sale_como_pendiente_y_no_como_ok(): void
    {
        $email = ProcessedEmail::factory()->create();

        $this->stage($email, 'received', 'passed');
        $this->stage($email, 'subject_filter', 'failed', error: 'el asunto no coincide');
        // `download` nunca se ejecutó: el pipeline se cortó ahí.

        $html = $this->renderFor($email);

        $this->assertStringContainsString('gdv-pipeline-step--pending', $html);
        $this->assertStringContainsString('Pendiente', $html);
    }

    public function test_el_error_se_muestra_en_rojo_y_no_solo_en_el_detalle_plegado(): void
    {
        $email = ProcessedEmail::factory()->create();

        $this->stage($email, 'received', 'passed');
        $this->stage($email, 'detect_kind', 'failed', error: 'No se pudo leer el PDF', message: 'intentando de nuevo');

        $html = $this->renderFor($email);

        // El texto del error y su alerta están en el panel de metadatos, que es
        // lo que se ve sin abrir nada.
        $this->assertStringContainsString('No se pudo leer el PDF', $html);
        $this->assertStringContainsString('gdv-error-alert', $html);

        // Y el paso en sí va en rojo, no de gris.
        $this->assertStringContainsString('gdv-pipeline-step--failed', $html);
        $this->assertStringContainsString('gdv-step-sub--error', $html);
        $this->assertStringContainsString('Falló', $html);
    }

    public function test_cada_paso_abre_el_detalle_de_su_etapa_en_pestana_nueva(): void
    {
        $email = ProcessedEmail::factory()->create();

        $this->stage($email, 'received', 'passed');

        $html = $this->renderFor($email);

        $this->assertStringContainsString(
            route('jobs.stage.show', ['uuid' => $email->uuid, 'stage_key' => 'received', 'attachment_id' => null]),
            $html,
        );
        $this->assertStringContainsString('target="_blank"', $html);
    }

    public function test_las_etapas_por_adjunto_se_dibujan_una_rama_por_archivo(): void
    {
        $email = ProcessedEmail::factory()->create();

        $this->stage($email, 'received', 'passed');
        $this->stage($email, 'download', 'passed');

        foreach (['solicitud.pdf', 'cedula.pdf'] as $index => $filename) {
            $attachment = $this->attachment($email, $filename);

            foreach (['detect_kind', 'extract_text', 'classify', 'extract_fields'] as $offset => $key) {
                $this->stage($email, $key, 'passed', attachment: $attachment, sequence: $index * 10 + $offset);
            }
        }

        $html = $this->renderFor($email);

        // Una rama por adjunto: el nombre del archivo encabeza su propia
        // columna de pasos, en vez de cuatro etapas sueltas sin dueño.
        $this->assertStringContainsString('📄 solicitud.pdf', $html);
        $this->assertStringContainsString('📄 cedula.pdf', $html);

        // 2 etapas + 4 del análisis por archivo × 2 archivos + 4 sin correr.
        $this->assertSame(10, substr_count($html, 'class="gdv-pipeline-step gdv-pipeline-step--passed'));
    }

    public function test_reprocesar_muestra_un_grafo_por_corrida(): void
    {
        $email = ProcessedEmail::factory()->create();

        $this->stage($email, 'received', 'passed', run: 1);
        $this->stage($email, 'done', 'passed', run: 1);

        $this->stage($email, 'received', 'passed', run: 2);
        $this->stage($email, 'done', 'failed', run: 2, error: 'cayó el worker');

        $html = $this->renderFor($email);

        $this->assertStringContainsString('Ejecución #2', $html);
        $this->assertStringContainsString('Ejecución #1', $html);

        // La más reciente va primera: es la que interesa mirar.
        $this->assertLessThan(
            strpos($html, 'Ejecución #1'),
            strpos($html, 'Ejecución #2'),
            'la corrida más reciente tiene que ir arriba',
        );
    }

    public function test_los_documentos_que_faltan_se_muestran_con_titulo_y_no_con_el_key(): void
    {
        $email = ProcessedEmail::factory()->create();

        $this->stage($email, 'received', 'passed');

        ValidationResult::query()->create([
            'email_id'  => $email->id,
            'rule_name' => 'fase1_vehiculo',
            'code'      => 'FALTAN_DOCUMENTOS',
            'status'    => 'fail',
            'severity'  => 'error',
            'message'   => 'Faltan documentos requeridos',
            'facts_json' => [
                'missing'  => ['solicitud', 'ine'],
                'required' => ['factura', 'solicitud', 'ine', 'poliza_vigente'],
                'counts'   => ['factura' => 1],
            ],
        ]);

        $html = $this->renderFor($email);

        // Esta es la razón de existir del panel: los faltantes se leen sin
        // tener que abrir el `ValidationResult` y traducir keys a mano.
        $this->assertStringContainsString('Faltan 2', $html);
        $this->assertStringContainsString('de 4 requeridos', $html);

        $this->assertSame(
            ['Solicitud', 'Cédula de identidad'],
            $this->missingChips($html),
            'los faltantes se muestran como títulos, no como keys de config',
        );
    }

    public function test_sin_documentos_faltantes_no_hay_panel_de_faltantes(): void
    {
        $email = ProcessedEmail::factory()->create();

        $this->stage($email, 'received', 'passed');
        $this->stage($email, 'done', 'passed');

        $html = $this->renderFor($email);

        $this->assertStringNotContainsString('gdv-missing', $html);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function renderFor(ProcessedEmail $email): string
    {
        $email->load(['stages.attachment', 'attachments', 'validationResults']);

        $runs = JobInfolist::runsOf($email);

        if ($runs === []) {
            return $this->withoutStyle(view('filament.jobs.timeline', [
                'email' => $email,
                'runs'  => [],
                'run'   => null,
                'index' => 0,
            ])->render());
        }

        $html = '<div class="space-y-4">';

        foreach ($runs as $index => $run) {
            $html .= view('filament.jobs.timeline', [
                'email' => $email,
                'runs'  => $runs,
                'run'   => $run,
                'index' => $index,
            ])->render();
        }

        // La vista lleva el CSS inline. Los asserts de este test cuentan
        // CLASES en el markup (`.gdv-pipeline-step--passed`), y las
        // definiciones dentro del `<style>` contienen los mismos nombres: sin
        // sacarlo, `substr_count` cuenta cada selector y las cuentas dan el
        // doble o el triple.
        return $this->withoutStyle($html.'</div>');
    }

    /** Los títulos del panel de faltantes, en orden. */
    private function missingChips(string $html): array
    {
        preg_match_all('#<span class="gdv-missing__chip">(.*?)</span>#s', $html, $matches);

        return array_map(
            static fn (string $chip): string => trim(html_entity_decode(strip_tags($chip), ENT_QUOTES | ENT_HTML5)),
            $matches[1] ?? [],
        );
    }

    private function withoutStyle(string $html): string
    {
        return preg_replace('#<style\b[^>]*>.*?</style>#si', '', $html) ?? $html;
    }

    private function stage(
        ProcessedEmail $email,
        string $key,
        string $status,
        int $sequence = 0,
        int $run = 1,
        ?Attachment $attachment = null,
        ?string $message = null,
        ?string $error = null,
    ): JobStage {
        return JobStage::query()->create([
            'email_id'      => $email->id,
            'attachment_id' => $attachment?->id,
            'stack'         => 'a',
            'stage_key'     => $key,
            'sequence'      => $sequence,
            'run'           => $run,
            'status'        => $status,
            'started_at'    => now()->subMinute(),
            'finished_at'   => now(),
            'duration_ms'   => 120,
            'message'       => $message,
            'error'         => $error,
        ]);
    }

    private function attachment(ProcessedEmail $email, string $filename): Attachment
    {
        $path = $this->storageRoot.'/'.$filename;
        File::put($path, '%PDF-1.4 demo');

        return $email->attachments()->create([
            'filename'      => $filename,
            'local_path'    => $path,
            'sha256'        => hash('sha256', $filename),
            'size_bytes'    => 15,
            'detected_kind' => 'application/pdf',
        ]);
    }
}
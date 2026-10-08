<?php

namespace Tests\Feature;

use App\Filament\Resources\Jobs\Schemas\JobInfolist;
use App\Models\Attachment;
use App\Models\JobStage;
use App\Models\ProcessedEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * El grafo de etapas del detalle del job.
 *
 * Cubre lo que la vista promete, que es distinto de lo que promete el pipeline:
 * que se vea como un pipeline, que cada nodo diga qué hace, que los adjuntos se
 * puedan descargar y que un fallo quede en rojo. Es una vista, así que el test
 * mira el HTML: un `assertSee` sobre el texto o la clase que el usuario ve es la
 * única forma de que un cambio de markup no rompa nada en silencio.
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

    public function test_job_sin_etapas_muestra_el_estado_vacio_y_no_una_tabla(): void
    {
        $html = $this->renderFor(ProcessedEmail::factory()->create());

        $this->assertStringContainsString('todavía no tiene etapas', $html);
        $this->assertStringNotContainsString('Ejecución #', $html);
    }

    public function test_dibuja_un_nodo_por_etapa_del_pipeline_en_orden(): void
    {
        $email = ProcessedEmail::factory()->create();

        foreach (['received', 'subject_filter', 'download', 'phase1_count', 'phase2_validate', 'reply', 'done'] as $i => $key) {
            $this->stage($email, $key, 'passed', sequence: $i);
        }

        $html = $this->renderFor($email);

        foreach (['Recibido', 'Filtro de asunto', 'Descarga de adjuntos', 'Fase 1 · Conteo', 'Fase 2 · Validación', 'Respuesta', 'Finalizado'] as $label) {
            $this->assertStringContainsString($label, $html, "falta el nodo «{$label}»");
        }

        // Los conectores son lo que convierte una fila de etiquetas en un
        // pipeline. Sin ellos la vista "anda" pero no dice lo que dice.
        $this->assertStringContainsString('gdv-arrow', $html);
        $this->assertSame(6, substr_count($html, 'class="gdv-arrow '), 'cada par de nodos va unido por un conector');
    }

    public function test_una_etapa_que_no_corrio_sale_punteada_y_no_como_ok(): void
    {
        $email = ProcessedEmail::factory()->create();

        $this->stage($email, 'received', 'passed');
        $this->stage($email, 'subject_filter', 'failed', error: 'el asunto no coincide');
        // `download` nunca se ejecutó: el pipeline se cortó ahí.

        $html = $this->renderFor($email);

        $this->assertStringContainsString('No corrió', $html);
        $this->assertStringContainsString('gdv-node--pending', $html);
        $this->assertStringContainsString('incompleta', $html);
    }

    public function test_el_error_se_muestra_en_rojo_y_arriba_de_todo(): void
    {
        $email = ProcessedEmail::factory()->create();

        $this->stage($email, 'received', 'passed');
        $this->stage($email, 'detect_kind', 'failed', error: 'No se pudo leer el PDF', message: 'intentando de nuevo');

        $html = $this->renderFor($email);

        $this->assertStringContainsString('No se pudo leer el PDF', $html);
        $this->assertStringContainsString('gdv-panel__error', $html);
        $this->assertStringContainsString('gdv-node--failed', $html);

        // Y NO puede quedar solo en rojo el detalle plegado: tiene que estar en
        // la ficha del nodo, que es lo que se ve sin hacer nada más.
        $this->assertStringContainsString('Falló', $html);
    }

    public function test_el_nodo_explica_que_hace_la_etapa(): void
    {
        $email = ProcessedEmail::factory()->create();
        $this->stage($email, 'phase2_validate', 'passed');

        $html = $this->renderFor($email);

        // El texto viene de `PipelineStage::description()`: la vista no repite
        // la explicación, así que no puede quedar vieja si cambia el pipeline.
        $this->assertStringContainsString('Se aplicaron las reglas / prompts de validación', $html);
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

        $this->assertStringContainsString('solicitud.pdf', $html);
        $this->assertStringContainsString('cedula.pdf', $html);

        // Una rama por adjunto: los cuatro nodos de la rama cuelgan de una
        // bifurcación, no de la línea principal.
        // Una bifurcación (la salida de "Descarga de adjuntos") con dos ramas, una
        // por adjunto.
        $this->assertSame(1, substr_count($html, '<div class="gdv-branch">'));
        $this->assertSame(2, substr_count($html, '<div class="gdv-lane">'), 'una rama por adjunto');
    }

    public function test_el_adjunto_se_puede_descargar_desde_el_nodo(): void
    {
        $email = ProcessedEmail::factory()->create();
        $attachment = $this->attachment($email, 'factura.pdf');

        $this->stage($email, 'received', 'passed');
        $this->stage($email, 'detect_kind', 'passed', attachment: $attachment);

        $html = $this->renderFor($email);

        $this->assertStringContainsString(
            route('attachments.download', ['uuid' => $attachment->uuid]),
            $html,
        );
        $this->assertStringContainsString('Descargar', $html);
    }

    public function test_un_adjunto_purgado_del_disco_lo_dice_en_vez_de_ofrecer_un_enlace_roto(): void
    {
        $email = ProcessedEmail::factory()->create();
        $attachment = $this->attachment($email, 'factura.pdf');
        $this->stage($email, 'detect_kind', 'passed', attachment: $attachment);

        File::delete($attachment->local_path);

        $html = $this->renderFor($email);

        $this->assertStringContainsString('ya no está en disco', $html);
        $this->assertStringNotContainsString(
            route('attachments.download', ['uuid' => $attachment->uuid]),
            $html,
        );
    }

    public function test_reprocesar_muestra_dos_grafos_uno_por_corrida(): void
    {
        $email = ProcessedEmail::factory()->create();

        $this->stage($email, 'received', 'passed', run: 1);
        $this->stage($email, 'done', 'passed', run: 1);

        $this->stage($email, 'received', 'passed', run: 2);
        $this->stage($email, 'done', 'failed', run: 2, error: 'cayó el worker');

        $html = $this->renderFor($email);

        $this->assertStringContainsString('Ejecución #2', $html);
        $this->assertStringContainsString('Ejecución #1', $html);
        $this->assertStringContainsString('última', $html);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function renderFor(ProcessedEmail $email): string
    {
        $email->load(['stages.attachment', 'attachments', 'validationResults']);

        $runs = JobInfolist::runsOf($email);

        if ($runs === []) {
            return view('filament.jobs.timeline', [
                'email' => $email,
                'runs'  => [],
                'run'   => null,
                'index' => 0,
            ])->render();
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
        // CLASES en el markup (`.gdv-lane`, `gdv-node--failed`), y las
        // definiciones dentro del `<style>` contienen los mismos nombres: sin
        // sacarlo, `substr_count` cuenta cada selector y las cuentas dan el
        // doble o el triple.
        return $this->withoutStyle($html.'</div>');
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
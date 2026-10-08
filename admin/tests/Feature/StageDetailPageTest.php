<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\JobStage;
use App\Models\ProcessedEmail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * La página que abre cada paso del pipeline (`jobs.stage.show`).
 *
 * El timeline se quedó con el pipeline y manda cada paso a una pestaña nueva;
 * esta es la otra mitad: qué hace la etapa, qué pasó si falló, qué se capturó y
 * cómo se baja el archivo. Es una vista, así que el test mira el HTML.
 */
class StageDetailPageTest extends TestCase
{
    use RefreshDatabase;

    private string $storageRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storageRoot = storage_path('app/attachments/test-stage-detail');
        File::ensureDirectoryExists($this->storageRoot);

        $this->actingAs(User::factory()->create());
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storageRoot);

        parent::tearDown();
    }

    // ── Casos ────────────────────────────────────────────────────────────────

    public function test_la_pagina_explica_que_hace_la_etapa(): void
    {
        $email = ProcessedEmail::factory()->create();
        $this->stage($email, 'phase2_validate', 'passed');

        // El texto viene de `PipelineStage::description()`: la vista no repite
        // la explicación, así que no puede quedar vieja si cambia el pipeline.
        $this->get($this->urlFor($email, 'phase2_validate'))
            ->assertSuccessful()
            ->assertSee('Se aplicaron las reglas / prompts de validación');
    }

    public function test_el_adjunto_se_puede_descargar_desde_el_detalle(): void
    {
        $email = ProcessedEmail::factory()->create();
        $attachment = $this->attachment($email, 'factura.pdf');

        $this->stage($email, 'detect_kind', 'passed', attachment: $attachment);

        $this->get($this->urlFor($email, 'detect_kind', $attachment))
            ->assertSuccessful()
            ->assertSee(route('attachments.download', ['uuid' => $attachment->uuid]))
            ->assertSee('Descargar Archivo');
    }

    public function test_un_adjunto_purgado_del_disco_no_ofrece_un_enlace_roto(): void
    {
        $email = ProcessedEmail::factory()->create();
        $attachment = $this->attachment($email, 'factura.pdf');

        $this->stage($email, 'detect_kind', 'passed', attachment: $attachment);

        File::delete($attachment->local_path);

        $this->get($this->urlFor($email, 'detect_kind', $attachment))
            ->assertSuccessful()
            ->assertDontSee(route('attachments.download', ['uuid' => $attachment->uuid]));
    }

    public function test_el_tipo_del_adjunto_se_muestra_con_titulo_y_no_con_la_key(): void
    {
        $email = ProcessedEmail::factory()->create();
        $attachment = $this->attachment($email, 'factura.pdf', docType: 'comprobante_domicilio');

        $this->stage($email, 'classify', 'passed', attachment: $attachment);

        $this->get($this->urlFor($email, 'classify', $attachment))
            ->assertSuccessful()
            ->assertSee('Comprobante de domicilio')
            ->assertDontSee('COMPROBANTE_DOMICILIO');
    }

    public function test_el_error_de_la_etapa_se_abre_arriba_y_en_rojo(): void
    {
        $email = ProcessedEmail::factory()->create();

        $this->stage($email, 'download', 'failed', error: 'No se pudo leer el PDF');

        $this->get($this->urlFor($email, 'download'))
            ->assertSuccessful()
            ->assertSee('No se pudo leer el PDF')
            ->assertSee('gdv-error-alert')
            ->assertSee('Falló');
    }

    public function test_un_job_inexistente_da_404(): void
    {
        $this->get(route('jobs.stage.show', [
            'uuid'       => 'no-existe',
            'stage_key'  => 'received',
        ]))->assertNotFound();
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function urlFor(ProcessedEmail $email, string $stageKey, ?Attachment $attachment = null): string
    {
        return route('jobs.stage.show', [
            'uuid'          => $email->uuid,
            'stage_key'     => $stageKey,
            'attachment_id' => $attachment?->id,
        ]);
    }

    private function stage(ProcessedEmail $email, string $key, string $status, ?Attachment $attachment = null, ?string $error = null): JobStage
    {
        return JobStage::query()->create([
            'email_id'      => $email->id,
            'attachment_id' => $attachment?->id,
            'stack'         => 'a',
            'stage_key'     => $key,
            'sequence'      => 0,
            'run'           => 1,
            'status'        => $status,
            'started_at'    => now()->subMinute(),
            'finished_at'   => now(),
            'duration_ms'   => 120,
            'error'         => $error,
        ]);
    }

    private function attachment(ProcessedEmail $email, string $filename, ?string $docType = null): Attachment
    {
        $path = $this->storageRoot.'/'.$filename;
        File::put($path, '%PDF-1.4 demo');

        return $email->attachments()->create([
            'filename'      => $filename,
            'local_path'    => $path,
            'sha256'        => hash('sha256', $filename),
            'size_bytes'    => 15,
            'detected_kind' => 'application/pdf',
            'doc_type'      => $docType,
        ]);
    }
}
<?php

namespace Tests\Feature;

use App\Models\ProcessedEmail;
use App\Models\User;
use App\Support\StoragePath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * La descarga de adjuntos, que NO se sirve por `public/storage`.
 *
 * Hay un caso que en local no se ve y en Docker sí: `ATTACHMENTS_ROOT` es una
 * ruta ABSOLUTA apuntando a un volumen montado. Si el controller valida el
 * archivo contra `storage_path('app/attachments')` —que en el contenedor no
 * existe— el `realpath` devuelve `false` y TODO download responde 404 aunque el
 * archivo esté ahí. Estos tests fijan la raíz desde `StoragePath`, que es la
 * que los workers y el downloader usan para escribir.
 */
class AttachmentDownloadTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        // Carpeta temporal FUERA de `storage/app`, para reproducir el layout
        // de Docker (volumen montado en otra ruta) en vez del de desarrollo.
        $this->root = sys_get_temp_dir().'/gdv-download-test-'.uniqid();
        File::ensureDirectoryExists($this->root);

        config(['gmail_docs.attachments.root' => $this->root]);

        $this->actingAs(User::factory()->create());
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    public function test_descarga_el_adjunto_con_el_nombre_original(): void
    {
        $attachment = $this->attachment('Terapias Dr. Rodriguez.pdf', '%PDF-1.4 demo');

        $response = $this->get(route('attachments.download', ['uuid' => $attachment->uuid]));

        $response->assertSuccessful();
        $this->assertSame('%PDF-1.4 demo', $response->streamedContent());
        $this->assertStringContainsString(
            'attachment; filename="Terapias Dr. Rodriguez.pdf"',
            $response->headers->get('content-disposition'),
        );
    }

    public function test_sin_sesion_de_panel_no_se_descarga(): void
    {
        auth()->logout();
        $this->app['auth']->forgetGuards();

        $attachment = $this->attachment('factura.pdf');

        $this->get(route('attachments.download', ['uuid' => $attachment->uuid]))
            ->assertRedirect();
    }

    public function test_un_adjunto_fuera_de_la_raiz_de_adjuntos_no_se_sirve(): void
    {
        // El `local_path` viene de la base, pero el control vale: un `..` o un
        // path malicio serviría `/etc/passwd`.
        $outside = tempnam(sys_get_temp_dir(), 'gdv_outside_');
        File::put($outside, 'no soy un adjunto');

        $attachment = $this->attachment('factura.pdf');
        $attachment->forceFill(['local_path' => $outside])->save();

        $this->get(route('attachments.download', ['uuid' => $attachment->uuid]))
            ->assertNotFound();

        File::delete($outside);
    }

    public function test_un_adjunto_purgado_del_disco_regresa_al_job_en_vez_de_un_404(): void
    {
        $attachment = $this->attachment('factura.pdf');
        File::delete($attachment->local_path);

        // El enlace desaparece del panel, pero si alguien tenía la URL guardada
        // lo razonable es devolverlo al job, no mostrarle un error.
        $this->get(route('attachments.download', ['uuid' => $attachment->uuid]))
            ->assertRedirect(route('filament.admin.resources.jobs.view', ['record' => $attachment->email_id]));
    }

    public function test_el_zip_incluye_los_adjuntos_que_siguen_en_disco(): void
    {
        $email = ProcessedEmail::factory()->create();
        $this->attachment('factura.pdf', '%PDF-1.4 uno', $email);
        $this->attachment('solicitud.pdf', '%PDF-1.4 dos', $email);

        $response = $this->get(route('jobs.attachments.download-all', ['uuid' => $email->uuid]));

        $response->assertSuccessful();
        $this->assertSame('application/zip', $response->headers->get('content-type'));

        // El zip es temporal y se borra al enviarlo (`deleteFileAfterSend`), así
        // que se copia antes de que la respuesta se envíe.
        $tmp = $this->root.'/descarga.zip';
        File::copy($response->baseResponse->getFile()->getPathname(), $tmp);

        $this->assertSame(
            ['factura.pdf', 'solicitud.pdf'],
            array_map('basename', array_keys($this->zipEntries($tmp))),
        );
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /** @return array<string, string> ruta interna → contenido */
    private function zipEntries(string $zipPath): array
    {
        $zip = new \ZipArchive();
        $zip->open($zipPath);
        $out = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $out[$zip->getNameIndex($i)] = (string) $zip->getFromIndex($i);
        }

        $zip->close();

        return $out;
    }

    private function attachment(string $filename, string $contents = '%PDF-1.4 demo', ?ProcessedEmail $email = null): \App\Models\Attachment
    {
        $email ??= ProcessedEmail::factory()->create();

        $path = $this->root.'/'.uniqid().'_'.$filename;
        File::put($path, $contents);

        return $email->attachments()->create([
            'filename'      => $filename,
            'local_path'    => $path,
            'sha256'        => hash('sha256', $path),
            'size_bytes'    => strlen($contents),
            'detected_kind' => 'application/pdf',
        ]);
    }

    public function test_la_raiz_por_defecto_cuadra_con_los_adjuntos(): void
    {
        // Guarda de que el test usa la misma raíz que el downloader.
        $this->assertSame($this->root, StoragePath::attachments());
    }
}
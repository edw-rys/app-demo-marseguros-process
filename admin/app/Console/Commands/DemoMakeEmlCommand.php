<?php

namespace App\Console\Commands;

use App\Services\Demo\EmlWriter;
use App\Support\StoragePath;
use Illuminate\Console\Command;

/**
 * Genera los `.eml` de la carpeta de demo.
 *
 * Los correos cubren a propósito los casos que hay que poder demostrar:
 * un expediente completo (validado), uno con un documento que falta (CU-02),
 * un `.exe` renombrado de PDF (CU-04) y un PDF escaneado sin capa de texto
 * (que obliga a OCR en Stack A).
 */
class DemoMakeEmlCommand extends Command
{
    protected $signature = 'demo:make-eml {--force : Sobrescribe los .eml existentes}';

    protected $description = 'Genera correos .eml de ejemplo para probar el pipeline sin Gmail';

    public function handle(EmlWriter $writer): int
    {
        $directory = StoragePath::demoInbox();

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            $this->components->error("No se pudo crear '{$directory}'.");

            return self::FAILURE;
        }

        foreach ($writer->samples() as $name => $sample) {
            $path = $directory.'/'.$name.'.eml';

            if (file_exists($path) && ! $this->option('force')) {
                $this->components->twoColumnDetail($name, 'ya existe (usa --force)');
                continue;
            }

            file_put_contents($path, $writer->build($sample));
            $this->components->twoColumnDetail(
                $name.'.eml',
                count($sample['attachments']).' adjunto(s)'
                    .' · '.mb_strlen($writer->build($sample)).' bytes',
            );
        }

        $this->components->info("Correos de demo en '{$directory}'.");
        $this->components->info('Procesa con: php artisan demo:ingest');

        return self::SUCCESS;
    }
}
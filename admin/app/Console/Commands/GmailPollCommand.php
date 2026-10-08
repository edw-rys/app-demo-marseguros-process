<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Loop de polling para desarrollo.
 *
 * Delega en `gmail:sync`, que es el que tiene la lógica. La diferencia es que
 * este se queda corriendo y duerme entre iteraciones.
 */
class GmailPollCommand extends Command
{
    protected $signature = 'gmail:poll
                            {--once : Una sola pasada y sale}
                            {--sleep=60 : Segundos entre iteraciones}
                            {--stack= : Fuerza el stack (a|b)}';

    protected $description = 'Loop de polling de Gmail para desarrollo';

    public function handle(): int
    {
        if ($this->option('once')) {
            return $this->callSync();
        }

        $sleep = max(10, (int) $this->option('sleep'));

        $this->components->info("Polling cada {$sleep}s. Ctrl+C para salir.");

        while (true) {
            $this->callSync();
            sleep($sleep);
        }
    }

    private function callSync(): int
    {
        $arguments = ['--stack' => $this->option('stack')];

        return $this->call('gmail:sync', array_filter($arguments));
    }
}
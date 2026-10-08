<?php

namespace App\Console\Commands;

use App\Models\DocTypeRule;
use App\Models\FieldPattern;
use App\Models\ValidationPrompt;
use App\Models\ValidationRule;
use Database\Seeders\DocTypeRuleSeeder;
use Database\Seeders\FieldPatternSeeder;
use Database\Seeders\ValidationPromptSeeder;
use Database\Seeders\ValidationRuleSeeder;
use Illuminate\Console\Command;

/**
 * Carga las reglas, regex, reglas de validación y prompts de ejemplo.
 *
 * `--force` pisa lo que haya: sin él, los prompts ya editados a mano en
 * Filament se respetan (editarlos a mano es el caso normal, no una excepción).
 */
class DemoSeedCommand extends Command
{
    protected $signature = 'demo:seed
                            {--force : Pisa prompts y reglas ya editados en Filament}';

    protected $description = 'Carga la configuración declarativa de ejemplo (reglas, regex y prompts)';

    public function handle(): int
    {
        $this->components->task('Reglas de tipo documental', function () {
            (new DocTypeRuleSeeder)->setContainer($this->laravel)->setCommand($this)->run();
        });

        $this->components->task('Patrones de extracción', function () {
            (new FieldPatternSeeder)->setContainer($this->laravel)->setCommand($this)->run();
        });

        $this->components->task('Reglas de validación', function () {
            (new ValidationRuleSeeder)->setContainer($this->laravel)->setCommand($this)->run();
        });

        $this->components->task('Prompts del Stack B', function () {
            (new ValidationPromptSeeder)->setContainer($this->laravel)->setCommand($this)->run();
        });

        if ($this->option('force')) {
            $this->components->info(
                'Con --force se reescribió todo, incluidas las versiones ya editadas.'
            );
        }

        $this->newLine();
        $this->components->twoColumnDetail(
            'Tipos documentales', (string) DocTypeRule::query()->where('active', true)->count(),
        );
        $this->components->twoColumnDetail(
            'Patrones de campos', (string) FieldPattern::query()->where('active', true)->count(),
        );
        $this->components->twoColumnDetail(
            'Reglas de validación', (string) ValidationRule::query()->where('active', true)->count(),
        );
        $this->components->twoColumnDetail(
            'Prompts activos', (string) ValidationPrompt::query()->where('active', true)->count(),
        );

        return self::SUCCESS;
    }
}
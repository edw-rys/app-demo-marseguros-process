<?php

namespace App\Filament\Resources\Jobs\Actions;

use App\Jobs\ProcessEmailJob;
use App\Models\AnalysisCache;
use App\Models\ProcessedEmail;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;

/**
 * Cambia el stack con el que se reprocesa el job.
 *
 * Es la forma de comparar A y B sobre el mismo correo sin pasar por consola.
 * La cache por hash es opcional: sin invalidarla, un archivo ya analizado con
 * ese stack reutiliza el resultado anterior y no se vuelve a llamar al worker.
 */
class SwitchStackAction
{
    public static function make(): Action
    {
        return Action::make('switch_stack')
            ->label('Cambiar stack y reprocesar')
            ->icon('heroicon-o-arrows-right-left')
            ->color('gray')
            ->schema([
                Radio::make('stack')
                    ->label('Stack')
                    ->options([
                        'a' => 'Stack A · OCR local (Tesseract + reglas)',
                        'b' => 'Stack B · OpenRouter + Gemini Flash Lite',
                    ])
                    ->default(fn (ProcessedEmail $record): string => $record->pipeline_stack)
                    ->required(),

                Toggle::make('clear_cache')
                    ->label('Invalidar la cache por hash')
                    ->helperText(
                        'Si está apagado y este archivo ya se analizó con ese stack, '
                        .'se reutiliza el resultado guardado y no se vuelve a llamar al worker.'
                    )
                    ->default(false),
            ])
            ->action(function (array $data, ProcessedEmail $record): void {
                $record->forceFill(['pipeline_stack' => $data['stack']])->save();

                if ($data['clear_cache'] ?? false) {
                    $record->attachments()->pluck('sha256')
                        ->each(fn (string $sha) => AnalysisCache::forget($sha, $data['stack']));
                }

                ProcessEmailJob::dispatch($record->uuid);

                $record->refresh();

                Notification::make()
                    ->success()
                    ->title('Job reprocesado')
                    ->body(
                        "Stack {$data['stack']} · estado '{$record->status}'. "
                        .'La corrida nueva aparece al final del timeline.'
                    )
                    ->persistent()
                    ->send();
            });
    }
}
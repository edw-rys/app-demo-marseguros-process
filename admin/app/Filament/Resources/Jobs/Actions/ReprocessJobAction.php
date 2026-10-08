<?php

namespace App\Filament\Resources\Jobs\Actions;

use App\Jobs\ProcessEmailJob;
use App\Models\ProcessedEmail;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

/**
 * Reprocesa el job con el pipeline completo.
 *
 * `job_stages` es append-only a propósito: la corrida anterior no se borra, se
 * queda arriba del timeline como registro de por qué se reprocesó. Por eso el
 * aviso lo dice explícitamente.
 */
class ReprocessJobAction
{
    public static function make(): Action
    {
        return Action::make('reprocess')
            ->label('Reprocesar')
            ->icon('heroicon-o-arrow-path')
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading('Reprocesar este job')
            ->modalDescription(
                'Se vuelve a ejecutar el pipeline completo. La corrida actual NO se borra: '
                .'el timeline es append-only y la nueva corrida se agrega al final.'
            )
            ->modalSubmitActionLabel('Reprocesar')
            ->action(function (ProcessedEmail $record): void {
                ProcessEmailJob::dispatch($record->uuid);

                $record->refresh();

                Notification::make()
                    ->success()
                    ->title('Job reprocesado')
                    ->body(
                        "Estado final: '{$record->status}'. "
                        .'El timeline tiene ahora '.$record->stages()->count().' etapas.'
                    )
                    ->persistent()
                    ->send();
            });
    }
}
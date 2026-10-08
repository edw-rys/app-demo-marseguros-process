<?php

namespace App\Filament\Resources\WatchStates\Actions;

use App\Services\Gmail\MailboxSynchronizer;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

/**
 * "Sincronizar ahora": dispara un poll manual contra Gmail.
 *
 * Corre el mismo `MailboxSynchronizer` que usa `php artisan gmail:sync`, así
 * que lo que se ve desde el botón y lo que se ve desde el comando son lo mismo.
 */
class SyncNowAction
{
    public static function make(): Action
    {
        return Action::make('syncNow')
            ->label('Sincronizar ahora')
            ->icon('heroicon-o-arrow-path')
            ->color('info')
            // Habla con la API de Google: puede tardar y no tiene sentido
            // dejar el botón ejecutándose mientras el usuario navega.
            ->modalHeading('Sincronizando con Gmail')
            ->modalDescription('Consultando el historial de cada buzón vigilado.')
            ->action(function (): void {
                $result = app(MailboxSynchronizer::class)->sync();

                if ($result['errors'] !== []) {
                    Notification::make()
                        ->title('Sincronización con errores')
                        ->body(implode("\n", $result['errors']))
                        ->danger()
                        ->persistent()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Sincronizado')
                    ->body(sprintf(
                        '%d revisados · %d coinciden con el filtro · %d jobs nuevos',
                        $result['scanned'],
                        $result['matched'],
                        count($result['created']),
                    ))
                    ->success()
                    ->send();
            });
    }
}
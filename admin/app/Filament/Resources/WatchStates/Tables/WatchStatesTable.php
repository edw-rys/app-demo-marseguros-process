<?php

namespace App\Filament\Resources\WatchStates\Tables;

use App\Filament\Resources\WatchStates\Actions\SyncNowAction;
use App\Models\WatchState;
use App\Services\Gmail\GmailAuthMode;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Listado de buzones vigilados.
 *
 * `last_poll_at` es la columna que importa: un buzón que hace mucho que no se
 * sincronizó es, casi siempre, un buzón con un error de credenciales.
 */
class WatchStatesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user_email')
                    ->label('Buzón')
                    ->searchable()
                    ->sortable()
                    ->icon('heroicon-o-envelope'),

                TextColumn::make('history_id')
                    ->label('Cursor')
                    ->badge()
                    ->color('gray')
                    ->description('historyId de Gmail: el cursor del watcher'),

                TextColumn::make('last_poll_at')
                    ->label('Última sincronización')
                    ->since()
                    ->sortable()
                    ->placeholder('Nunca')
                    ->color(fn (?WatchState $record): string => $record?->last_poll_at === null
                        ? 'danger'
                        : ($record->last_poll_at->diffInHours() >= 1 ? 'warning' : 'success')),

                // Nada de `visible()` por registro: Filament evalúa la visibilidad al
                // mapear columnas, cuando todavía no hay fila, y una closure
                // que devuelve false en null esconde la columna del listado
                // entero. El `placeholder` ya cubre las filas sin error.
                TextColumn::make('last_error')
                    ->label('Último error')
                    ->color('danger')
                    ->wrap()
                    ->limit(60)
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->defaultSort('user_email')
            ->headerActions([
                SyncNowAction::make(),
            ])
            ->recordActions([
                SyncNowAction::make(),
            ])
            ->emptyStateHeading('No hay buzones vigilados')
            ->emptyStateDescription(
                // El texto tiene que encajar con el modo de credencial: en
                // OAuth no hay un GOOGLE_MAILBOXES que configurar, hay una
                // cuenta que autorizar.
                GmailAuthMode::current()->authorizesAMailbox()
                    ? 'Autorizá una cuenta en Conexión con Gmail y corré `php artisan gmail:sync`.'
                    : 'Configurá GOOGLE_MAILBOXES en admin/.env y corré `php artisan gmail:sync`.'
            );
    }
}
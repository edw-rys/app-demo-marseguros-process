<?php

namespace App\Filament\Resources\WatchStates;

use App\Filament\NavigationGroup;
use App\Filament\Resources\WatchStates\Actions\SyncNowAction;
use App\Filament\Resources\WatchStates\Pages\ListWatchStates;
use App\Filament\Resources\WatchStates\Tables\WatchStatesTable;
use App\Models\WatchState;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Estado del watcher de Gmail (RF-01).
 *
 * Cada fila es un buzón vigilado con su cursor de `historyId`. El botón
 * "Sincronizar ahora" corre un poll manual contra Gmail — es la forma de ver
 * en el admin, sin esperar al scheduler, que un buzón está andando.
 */
class WatchStateResource extends Resource
{
    protected static ?string $model = WatchState::class;

    protected static ?string $recordTitleAttribute = 'user_email';

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-envelope-open';

    protected static string | UnitEnum | null $navigationGroup = NavigationGroup::Watchers;

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'buzón vigilado';

    protected static ?string $pluralModelLabel = 'buzones vigilados';

    public static function table(Table $table): Table
    {
        return WatchStatesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWatchStates::route('/'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        return null;
    }

    /** Filament v5: sin `form()` y sin páginas de create/edit. */
    public static function canCreate(): bool
    {
        return false;
    }
}
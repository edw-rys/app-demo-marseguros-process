<?php

namespace App\Filament\Resources\Jobs;

use App\Filament\NavigationGroup;
use App\Filament\Resources\Jobs\Pages\ListJobs;
use App\Filament\Resources\Jobs\Pages\ViewJob;
use App\Filament\Resources\Jobs\Schemas\JobInfolist;
use App\Filament\Resources\Jobs\Tables\JobsTable;
use App\Models\ProcessedEmail;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use UnitEnum;

/**
 * El admin pedido: listado de jobs y, en la página de detalle, el timeline de
 * etapas tal como se ejecutó.
 *
 * `table()` e `infolist()` delegan en clases propias porque son largos y
 * cambian mucho más que el resto del resource.
 */
class JobResource extends Resource
{
    protected static ?string $model = ProcessedEmail::class;

    protected static ?string $recordTitleAttribute = 'subject';

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-inbox-stack';

    protected static string | UnitEnum | null $navigationGroup = NavigationGroup::Pipeline;

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'job';

    protected static ?string $pluralModelLabel = 'jobs';

    public static function table(Table $table): Table
    {
        return JobsTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return JobInfolist::configure($schema);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListJobs::route('/'),
            'view'  => ViewJob::route('/{record}'),
        ];
    }

    /** El badge del menú muestra cuántos jobs están corriendo ahora. */
    public static function getNavigationBadge(): ?string
    {
        $count = static::getModel()::query()
            ->where('status', ProcessedEmail::STATUS_PROCESSING)
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    /** Color y etiqueta del estado, compartidos por tabla e infolist. */
    public static function statusColor(string $status): string
    {
        return match ($status) {
            ProcessedEmail::STATUS_VALIDATED  => 'success',
            ProcessedEmail::STATUS_FAILED     => 'danger',
            ProcessedEmail::STATUS_REVIEW     => 'warning',
            ProcessedEmail::STATUS_PROCESSING => 'info',
            default                           => 'gray',
        };
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            ProcessedEmail::STATUS_VALIDATED  => 'Validado',
            ProcessedEmail::STATUS_FAILED     => 'Con errores',
            ProcessedEmail::STATUS_REVIEW     => 'Revisión manual',
            ProcessedEmail::STATUS_PROCESSING => 'Procesando',
            ProcessedEmail::STATUS_PENDING    => 'Pendiente',
            default                           => $status,
        };
    }
}
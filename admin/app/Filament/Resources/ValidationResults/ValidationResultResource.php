<?php

namespace App\Filament\Resources\ValidationResults;

use App\Filament\NavigationGroup;
use App\Filament\Resources\ValidationResults\Pages\ListValidationResults;
use App\Filament\Resources\ValidationResults\Tables\ValidationResultsTable;
use App\Models\ValidationResult;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Resultados de Fase 2, regla por regla.
 *
 * Es la tabla que responde "¿por qué se rechazó este documento?": una fila
 * por regla evaluada, con su veredicto y el mensaje que va a ver el cliente.
 */
class ValidationResultResource extends Resource
{
    protected static ?string $model = ValidationResult::class;

    protected static ?string $recordTitleAttribute = 'rule_name';

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static string | UnitEnum | null $navigationGroup = NavigationGroup::Evidence;

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'resultado';

    protected static ?string $pluralModelLabel = 'resultados';

    public static function table(Table $table): Table
    {
        return ValidationResultsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListValidationResults::route('/'),
        ];
    }
}
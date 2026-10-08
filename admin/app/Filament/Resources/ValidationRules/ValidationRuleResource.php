<?php

namespace App\Filament\Resources\ValidationRules;

use App\Filament\NavigationGroup;
use App\Filament\Resources\ValidationRules\Pages\EditValidationRule;
use App\Filament\Resources\ValidationRules\Pages\ListValidationRules;
use App\Filament\Resources\ValidationRules\Schemas\ValidationRuleForm;
use App\Filament\Resources\ValidationRules\Tables\ValidationRulesTable;
use App\Models\ValidationRule;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Stack A · reglas declarativas de Fase 2 (RF-07).
 *
 * El JSON es el de `json-rules-engine` (nodo). El requerimiento pedía esa
 * librería, que es JS/TS: aquí se ejecuta en Python con `python-rule-engine`,
 * que comparte la sintaxis. Ver docs/ARCHITECTURE.md.
 */
class ValidationRuleResource extends Resource
{
    protected static ?string $model = ValidationRule::class;

    protected static ?string $recordTitleAttribute = 'name';

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-scale';

    protected static string | UnitEnum | null $navigationGroup = NavigationGroup::StackA;

    protected static ?int $navigationSort = 3;

    protected static ?string $modelLabel = 'regla de validación';

    protected static ?string $pluralModelLabel = 'reglas de validación';

    public static function form(Schema $schema): Schema
    {
        return ValidationRuleForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ValidationRulesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListValidationRules::route('/'),
            'edit'  => EditValidationRule::route('/{record}/edit'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        $active = ValidationRule::query()->where('active', true)->count();
        $total = ValidationRule::query()->count();

        return $active === $total ? null : "{$active}/{$total}";
    }
}
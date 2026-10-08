<?php

namespace App\Filament\Resources\DocTypeRules;

use App\Filament\NavigationGroup;
use App\Filament\Resources\DocTypeRules\Pages\EditDocTypeRule;
use App\Filament\Resources\DocTypeRules\Pages\ListDocTypeRules;
use App\Filament\Resources\DocTypeRules\Schemas\DocTypeRuleForm;
use App\Filament\Resources\DocTypeRules\Tables\DocTypeRulesTable;
use App\Models\DocTypeRule;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Stack A · reglas de tipo documental (RF-05).
 *
 * Se edita desde el admin porque el requerimiento pide *hot reload*: cambiar una
 * keyword surte efecto en el siguiente job sin reiniciar el worker. Eso solo
 * funciona si Laravel lee la regla de la BD en cada llamada, y lo hace
 * (`AnalyzerClient::docTypeRules()`).
 */
class DocTypeRuleResource extends Resource
{
    protected static ?string $model = DocTypeRule::class;

    protected static ?string $recordTitleAttribute = 'label';

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-tag';

    protected static string | UnitEnum | null $navigationGroup = NavigationGroup::StackA;

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'regla de tipo';

    protected static ?string $pluralModelLabel = 'reglas de tipo';

    public static function form(Schema $schema): Schema
    {
        return DocTypeRuleForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DocTypeRulesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDocTypeRules::route('/'),
            'edit'  => EditDocTypeRule::route('/{record}/edit'),
        ];
    }

    /** Badge con cuántas reglas están activas: el número que de verdad importa. */
    public static function getNavigationBadge(): ?string
    {
        $active = DocTypeRule::query()->where('active', true)->count();
        $total = DocTypeRule::query()->count();

        return $active === $total ? null : "{$active}/{$total}";
    }
}
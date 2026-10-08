<?php

namespace App\Filament\Resources\FieldPatterns;

use App\Filament\NavigationGroup;
use App\Filament\Resources\FieldPatterns\Pages\EditFieldPattern;
use App\Filament\Resources\FieldPatterns\Pages\ListFieldPatterns;
use App\Filament\Resources\FieldPatterns\Schemas\FieldPatternForm;
use App\Filament\Resources\FieldPatterns\Tables\FieldPatternsTable;
use App\Models\FieldPattern;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Stack A · regex de extracción de campos (RF-06).
 *
 * Editable en caliente por el mismo motivo que las reglas de tipo: Laravel
 * manda el dict `{nombre: regex}` en cada llamada al worker, así que un cambio
 * surte efecto en el siguiente job.
 */
class FieldPatternResource extends Resource
{
    protected static ?string $model = FieldPattern::class;

    protected static ?string $recordTitleAttribute = 'name';

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-code-bracket';

    protected static string | UnitEnum | null $navigationGroup = NavigationGroup::StackA;

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'patrón de campo';

    protected static ?string $pluralModelLabel = 'patrones de campo';

    public static function form(Schema $schema): Schema
    {
        return FieldPatternForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FieldPatternsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFieldPatterns::route('/'),
            'edit'  => EditFieldPattern::route('/{record}/edit'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        $active = FieldPattern::query()->where('active', true)->count();
        $total = FieldPattern::query()->count();

        return $active === $total ? null : "{$active}/{$total}";
    }
}
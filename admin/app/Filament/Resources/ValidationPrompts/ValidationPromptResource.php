<?php

namespace App\Filament\Resources\ValidationPrompts;

use App\Filament\NavigationGroup;
use App\Filament\Resources\ValidationPrompts\Pages\EditValidationPrompt;
use App\Filament\Resources\ValidationPrompts\Pages\ListValidationPrompts;
use App\Filament\Resources\ValidationPrompts\Schemas\ValidationPromptForm;
use App\Filament\Resources\ValidationPrompts\Tables\ValidationPromptsTable;
use App\Models\ValidationPrompt;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Stack B · prompts versionados (RF-08).
 *
 * El prompt vive en la base, no en el código: cambiarlo surte efecto en el
 * siguiente job sin redeploy. El botón "Probar prompt" hace una llamada real
 * a OpenRouter con el texto de este registro y muestra el JSON que volvió, que
 * es la única forma fiable de comprobar un prompt antes de confiarle un job.
 */
class ValidationPromptResource extends Resource
{
    protected static ?string $model = ValidationPrompt::class;

    protected static ?string $recordTitleAttribute = 'name';

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-command-line';

    protected static string | UnitEnum | null $navigationGroup = NavigationGroup::StackB;

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'prompt';

    protected static ?string $pluralModelLabel = 'prompts';

    public static function form(Schema $schema): Schema
    {
        return ValidationPromptForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ValidationPromptsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListValidationPrompts::route('/'),
            'edit'  => EditValidationPrompt::route('/{record}/edit'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        $active = ValidationPrompt::query()->where('active', true)->count();
        $total = ValidationPrompt::query()->count();

        return $active === $total ? null : "{$active}/{$total}";
    }
}
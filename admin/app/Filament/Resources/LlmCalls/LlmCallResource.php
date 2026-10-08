<?php

namespace App\Filament\Resources\LlmCalls;

use App\Filament\NavigationGroup;
use App\Filament\Resources\LlmCalls\Pages\ListLlmCalls;
use App\Filament\Resources\LlmCalls\Tables\LlmCallsTable;
use App\Models\LlmCall;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Auditoría de las llamadas al LLM (RF-04, RF-10).
 *
 * Cada fila es una llamada real a OpenRouter con su request, su response y su
 * costo. Es el registro que permite responder "qué se le mandó al modelo" y
 * "cuánto costó", y detectar `degraded` cuando se cayó y se rehízo con A.
 */
class LlmCallResource extends Resource
{
    protected static ?string $model = LlmCall::class;

    protected static ?string $recordTitleAttribute = 'purpose';

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static string | UnitEnum | null $navigationGroup = NavigationGroup::StackB;

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'llamada al LLM';

    protected static ?string $pluralModelLabel = 'llamadas al LLM';

    public static function table(Table $table): Table
    {
        return LlmCallsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLlmCalls::route('/'),
        ];
    }
}
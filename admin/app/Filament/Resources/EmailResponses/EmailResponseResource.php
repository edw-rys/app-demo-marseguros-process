<?php

namespace App\Filament\Resources\EmailResponses;

use App\Filament\NavigationGroup;
use App\Filament\Resources\EmailResponses\Pages\ListEmailResponses;
use App\Filament\Resources\EmailResponses\Tables\EmailResponsesTable;
use App\Models\EmailResponse;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Respuestas que el bot le envió (o iba a enviar) al cliente (RF-09).
 *
 * Con `GMAIL_SEND_ENABLED=false` todas quedan en `dry_run`: el texto se
 * genera y se guarda igual, pero no sale. Es lo que permite revisar el texto
 * que escribiría antes de activar el envío real.
 */
class EmailResponseResource extends Resource
{
    protected static ?string $model = EmailResponse::class;

    protected static ?string $recordTitleAttribute = 'template';

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static string | UnitEnum | null $navigationGroup = NavigationGroup::Pipeline;

    protected static ?int $navigationSort = 3;

    protected static ?string $modelLabel = 'respuesta';

    protected static ?string $pluralModelLabel = 'respuestas';

    public static function table(Table $table): Table
    {
        return EmailResponsesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEmailResponses::route('/'),
        ];
    }
}
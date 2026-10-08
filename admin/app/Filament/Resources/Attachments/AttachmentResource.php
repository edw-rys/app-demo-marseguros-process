<?php

namespace App\Filament\Resources\Attachments;

use App\Filament\NavigationGroup;
use App\Filament\Resources\Attachments\Pages\ListAttachments;
use App\Filament\Resources\Attachments\Schemas\AttachmentInfolist;
use App\Filament\Resources\Attachments\Tables\AttachmentsTable;
use App\Models\Attachment;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Evidencia por adjunto: qué se detectó, cómo se clasificó y qué campos salieron.
 *
 * Es solo lectura a propósito: un adjunto es el resultado de un job, no
 * algo que se edite. Para corregirlo se reprocesa el job.
 */
class AttachmentResource extends Resource
{
    protected static ?string $model = Attachment::class;

    protected static ?string $recordTitleAttribute = 'filename';

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-paper-clip';

    protected static string | UnitEnum | null $navigationGroup = NavigationGroup::Pipeline;

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'adjunto';

    protected static ?string $pluralModelLabel = 'adjuntos';

    public static function table(Table $table): Table
    {
        return AttachmentsTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return AttachmentInfolist::configure($schema);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAttachments::route('/'),
        ];
    }
}
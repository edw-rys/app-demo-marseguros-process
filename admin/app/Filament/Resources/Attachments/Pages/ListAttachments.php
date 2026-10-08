<?php

namespace App\Filament\Resources\Attachments\Pages;

use App\Filament\Resources\Attachments\AttachmentResource;
use Filament\Resources\Pages\ListRecords;

class ListAttachments extends ListRecords
{
    protected static string $resource = AttachmentResource::class;

    protected function getHeaderSubheading(): ?string
    {
        return 'Solo lectura: cada adjunto es el resultado de un job. '
            .'Para corregirlo hay que reprocesar el correo.';
    }
}
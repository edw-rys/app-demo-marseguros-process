<?php

namespace App\Filament\Resources\EmailResponses\Pages;

use App\Filament\Resources\EmailResponses\EmailResponseResource;
use Filament\Resources\Pages\ListRecords;

class ListEmailResponses extends ListRecords
{
    protected static string $resource = EmailResponseResource::class;

    protected function getHeaderSubheading(): ?string
    {
        return 'Con `GMAIL_SEND_ENABLED=false` todas quedan en `dry_run`: el '
            .'texto se genera y se guarda, pero no sale. Ver `sent` significa '
            .'que el envío real está activado.';
    }
}
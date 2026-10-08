<?php

namespace App\Filament\Resources\LlmCalls\Pages;

use App\Filament\Resources\LlmCalls\LlmCallResource;
use Filament\Resources\Pages\ListRecords;

class ListLlmCalls extends ListRecords
{
    protected static string $resource = LlmCallResource::class;

    protected function getHeaderSubheading(): ?string
    {
        return 'Auditoría de cada llamada a OpenRouter. El filtro por defecto '
            .'muestra solo las degradadas: son las que indican que hay que '
            .'revisar la key o la cuota.';
    }
}
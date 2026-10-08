<?php

namespace App\Filament\Resources\DocTypeRules\Pages;

use App\Filament\Resources\DocTypeRules\DocTypeRuleResource;
use Filament\Resources\Pages\ListRecords;

class ListDocTypeRules extends ListRecords
{
    protected static string $resource = DocTypeRuleResource::class;

    protected function getHeaderSubheading(): ?string
    {
        return 'El worker lee estas reglas de la base en cada llamada: '
            .'editarlas surte efecto en el siguiente job, sin reiniciar nada.';
    }
}
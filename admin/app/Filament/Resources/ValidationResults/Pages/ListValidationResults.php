<?php

namespace App\Filament\Resources\ValidationResults\Pages;

use App\Filament\Resources\ValidationResults\ValidationResultResource;
use Filament\Resources\Pages\ListRecords;

class ListValidationResults extends ListRecords
{
    protected static string $resource = ValidationResultResource::class;

    protected function getHeaderSubheading(): ?string
    {
        return 'Una fila por regla evaluada en Fase 2. Solo `error` bloquea el '
            .'trámite; `warning` solo avisa.';
    }
}
<?php

namespace App\Filament\Resources\ValidationRules\Pages;

use App\Filament\Resources\ValidationRules\ValidationRuleResource;
use Filament\Resources\Pages\ListRecords;

class ListValidationRules extends ListRecords
{
    protected static string $resource = ValidationRuleResource::class;

    protected function getHeaderSubheading(): ?string
    {
        return 'Fase 2 del Stack A. Solo las reglas con severidad `error` '
            .'bloquean el trámite; `warning` solo avisa.';
    }
}
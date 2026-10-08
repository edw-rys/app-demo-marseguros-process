<?php

namespace App\Filament\Resources\FieldPatterns\Pages;

use App\Filament\Resources\FieldPatterns\FieldPatternResource;
use Filament\Resources\Pages\ListRecords;

class ListFieldPatterns extends ListRecords
{
    protected static string $resource = FieldPatternResource::class;

    protected function getHeaderSubheading(): ?string
    {
        return 'Regex de extracción del Stack A. El worker los lee de la base en '
            .'cada llamada, así que editar uno surte efecto en el siguiente job.';
    }
}
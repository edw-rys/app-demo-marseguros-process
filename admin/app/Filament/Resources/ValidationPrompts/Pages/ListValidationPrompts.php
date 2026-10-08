<?php

namespace App\Filament\Resources\ValidationPrompts\Pages;

use App\Filament\Resources\ValidationPrompts\ValidationPromptResource;
use Filament\Resources\Pages\ListRecords;

class ListValidationPrompts extends ListRecords
{
    protected static string $resource = ValidationPromptResource::class;

    protected function getHeaderSubheading(): ?string
    {
        return 'Prompts del Stack B, versionados en la base. Editar uno surte '
            .'efecto en el siguiente job sin reiniciar nada. El botón de ojo '
            .'azul "Probar prompt" hace una llamada real para ver qué devuelve.';
    }
}
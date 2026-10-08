<?php

namespace App\Filament\Resources\WatchStates\Pages;

use App\Filament\Resources\WatchStates\WatchStateResource;
use Filament\Resources\Pages\ListRecords;

class ListWatchStates extends ListRecords
{
    protected static string $resource = WatchStateResource::class;

    protected function getHeaderSubheading(): ?string
    {
        return 'Cada fila es un buzón vigilado con su cursor de historial. '
            .'Se crean solas la primera vez que se sincronizan; no se editan a mano.';
    }
}
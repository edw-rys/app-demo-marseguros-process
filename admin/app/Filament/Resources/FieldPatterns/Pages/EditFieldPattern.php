<?php

namespace App\Filament\Resources\FieldPatterns\Pages;

use App\Filament\Resources\FieldPatterns\FieldPatternResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditFieldPattern extends EditRecord
{
    protected static string $resource = FieldPatternResource::class;

    /**
     * Solo el borrado va en la cabecera: en Filament v5 no existe `SaveAction`,
     * `EditRecord` ya pone el botón de guardar al pie del formulario.
     *
     * @return array<\Filament\Actions\Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
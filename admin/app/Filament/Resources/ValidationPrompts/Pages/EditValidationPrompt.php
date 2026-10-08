<?php

namespace App\Filament\Resources\ValidationPrompts\Pages;

use App\Filament\Resources\ValidationPrompts\Actions\TestPromptAction;
use App\Filament\Resources\ValidationPrompts\ValidationPromptResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditValidationPrompt extends EditRecord
{
    protected static string $resource = ValidationPromptResource::class;

    /**
     * En Filament v5 no existe `SaveAction`: `EditRecord` ya pone el botón de
     * guardar al pie del formulario. En la cabecera solo va el borrado y la
     * prueba del prompt, que es la acción que se usa antes de guardar.
     *
     * @return array<\Filament\Actions\Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            TestPromptAction::make(),
            DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    /**
     * Sube la versión cuando cambia el texto.
     *
     * Se hace aquí y no en un observer porque solo aplica a la edición desde
     * el admin: un `firstOrCreate` desde un seeder no debe bumpear versiones.
     *
     * @param  array<string, mixed>  $data
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (isset($data['prompt_text'])
            && $this->record->prompt_text !== $data['prompt_text']) {
            $data['version'] = ((int) $this->record->version) + 1;
        }

        return $data;
    }
}
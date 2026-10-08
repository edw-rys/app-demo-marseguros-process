<?php

namespace App\Filament\Resources\DocTypeRules\Pages;

use App\Filament\Resources\DocTypeRules\DocTypeRuleResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditDocTypeRule extends EditRecord
{
    protected static string $resource = DocTypeRuleResource::class;

    /**
     * Solo el borrado va en la cabecera.
     *
     * En Filament v5 no existe `SaveAction`: `EditRecord` ya pone el botón de
     * guardar al pie del formulario a través de `getFormActions()`.
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
<?php

namespace App\Filament\Resources\ValidationRules\Pages;

use App\Filament\Resources\ValidationRules\ValidationRuleResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditValidationRule extends EditRecord
{
    protected static string $resource = ValidationRuleResource::class;

    /**
     * Solo el borrado va en la cabecera: en Filament v5 no existe `SaveAction`.
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
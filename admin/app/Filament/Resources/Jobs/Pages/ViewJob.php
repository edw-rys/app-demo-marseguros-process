<?php

namespace App\Filament\Resources\Jobs\Pages;

use App\Filament\Resources\Jobs\Actions\ReprocessJobAction;
use App\Filament\Resources\Jobs\Actions\SwitchStackAction;
use App\Filament\Resources\Jobs\JobResource;
use App\Models\ProcessedEmail;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

/**
 * Detalle del job: el timeline de etapas y las acciones de reproceso.
 *
 * No hay formulario editable: un job es un registro de auditoría. Lo que sí se
 * puede cambiar es con qué stack se reprocesa, que es una decisión operativa.
 */
class ViewJob extends ViewRecord
{
    protected static string $resource = JobResource::class;

    /** @return array<Action> */
    protected function getHeaderActions(): array
    {
        return [
            SwitchStackAction::make(),
            ReprocessJobAction::make(),
        ];
    }

    public function getMaxContentWidth(): \Filament\Support\Enums\Width | string | null
    {
        return \Filament\Support\Enums\Width::Full;
    }
}
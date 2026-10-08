<?php

namespace App\Http\Controllers;

use App\Enums\PipelineStage;
use App\Models\Attachment;
use App\Models\JobStage;
use App\Models\ProcessedEmail;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Vista de detalle completa de una etapa del pipeline (abierta en pestaña nueva).
 */
class StageDetailController extends Controller
{
    public function __invoke(Request $request, string $uuid, string $stage_key, ?int $attachment_id = null): View
    {
        $email = ProcessedEmail::query()->where('uuid', $uuid)->first();

        if ($email === null) {
            throw new NotFoundHttpException('El correo / job no fue encontrado.');
        }

        $pipelineStage = PipelineStage::tryFrom($stage_key);

        $query = JobStage::query()
            ->where('email_id', $email->id)
            ->where('stage_key', $stage_key);

        if ($attachment_id !== null) {
            $query->where('attachment_id', $attachment_id);
        }

        // Obtener la ejecución más reciente de esta etapa
        $stageRecord = $query->latest('id')->first();

        $attachment = $attachment_id !== null
            ? Attachment::query()->find($attachment_id)
            : $stageRecord?->attachment;

        return view('filament.jobs.stage-detail', [
            'email'         => $email,
            'stage'         => $stageRecord,
            'pipelineStage' => $pipelineStage,
            'stageKey'      => $stage_key,
            'attachment'    => $attachment,
        ]);
    }
}

<?php

namespace App\Services\Pipeline;

use App\Enums\PipelineStage;
use App\Enums\PipelineStatus;
use App\Models\JobStage;
use App\Models\ProcessedEmail;
use Illuminate\Support\Facades\DB;

/**
 * Registra cada etapa del pipeline en `job_stages`.
 *
 * Existe para que el timeline del admin no dependa de que cada etapa se acuerde
 * de anotarse: el pipeline envuelve su trabajo en `run()` y el registro sale
 * siempre, incluso si la etapa revienta.
 *
 * Append-only: reprocesar un job agrega filas nuevas, no borra las viejas.
 */
class StageRecorder
{
    private int $sequence = 0;

    private int $run;

    public function __construct(
        private readonly ProcessedEmail $email,
        private readonly string $stack = 'a',
    ) {
        // Continúa desde el último `sequence` usado, para que los reintentos
        // manuales queden ordenados después de los automáticos.
        $this->sequence = (int) JobStage::query()
            ->where('email_id', $email->id)
            ->max('sequence');

        // Cada recorder abre una corrida nueva: el `sequence` sigue subiendo,
        // así que el número de corrida es lo único que permite al admin
        // separar los intentos (ver `JobInfolist::runsOf`).
        $this->run = ((int) JobStage::query()
            ->where('email_id', $email->id)
            ->max('run')) + 1;
    }

    public function runNumber(): int
    {
        return $this->run;
    }

    /**
     * Ejecuta el trabajo de una etapa y registra el resultado.
     *
     * La excepción se propaga (para que el pipeline pueda cortarla) pero la
     * etapa queda registrada como `failed` antes de salir.
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @param  array<string, mixed>  $detail  Datos de entrada/salida para el admin.
     * @return T
     */
    public function run(
        PipelineStage $stage,
        callable $work,
        array $detail = [],
        ?int $attachmentId = null,
    ): mixed {
        $record = $this->start($stage, $attachmentId, $detail);
        $startedAt = microtime(true);

        try {
            $result = $work();

            $this->finish(
                $record,
                PipelineStatus::Passed,
                detail: $this->detailFromResult($detail, $result),
                durationMs: (int) round((microtime(true) - $startedAt) * 1000),
            );

            return $result;
        } catch (\Throwable $e) {
            $this->finish(
                $record,
                PipelineStatus::Failed,
                detail: $detail,
                error: $e->getMessage(),
                durationMs: (int) round((microtime(true) - $startedAt) * 1000),
                severity: 'error',
            );

            throw $e;
        }
    }

    /**
     * Registra una etapa sin ejecutar nada (resultado ya conocido).
     *
     * Se usa para las etapas que quedan `skipped` al cortarse el pipeline y
     * para los hitos que no involves trabajo real (`received`, `done`).
     */
    public function mark(
        PipelineStage $stage,
        PipelineStatus $status,
        array $detail = [],
        ?string $message = null,
        ?int $attachmentId = null,
        string $severity = 'info',
    ): JobStage {
        $record = $this->start($stage, $attachmentId, $detail);

        return $this->finish(
            $record,
            $status,
            detail: $detail,
            message: $message,
            durationMs: 0,
            severity: $severity,
        );
    }

    /**
     * Marca la etapa actual en `processed_emails.current_stage`.
     *
     * Es lo que lee la columna del listado del admin, así que se actualiza al
     * empezar cada etapa y no al terminarla: si el job muere a mitad, el admin
     * muestra dónde se quedó.
     */
    public function touchCurrentStage(PipelineStage $stage): void
    {
        $this->email->forceFill(['current_stage' => $stage->value])->save();
    }

    // ── Interno ──────────────────────────────────────────────────────────────

    private function start(
        PipelineStage $stage,
        ?int $attachmentId,
        array $detail
    ): JobStage {
        $this->touchCurrentStage($stage);
        $this->sequence++;

        return JobStage::query()->create([
            'email_id'      => $this->email->id,
            'run'           => $this->run,
            'attachment_id' => $attachmentId,
            'stack'         => $this->stack,
            'stage_key'     => $stage->value,
            'sequence'      => $this->sequence,
            'status'        => PipelineStatus::Running->value,
            'started_at'    => now(),
            'detail_json'   => $detail ?: null,
        ]);
    }

    private function finish(
        JobStage $record,
        PipelineStatus $status,
        ?array $detail = null,
        ?string $message = null,
        ?string $error = null,
        ?int $durationMs = null,
        string $severity = 'info',
    ): JobStage {
        $record->forceFill([
            'status'      => $status->value,
            'finished_at' => now(),
            'duration_ms' => $durationMs,
            'message'     => $message,
            'error'       => $error,
            'severity'    => $severity,
        ]);

        if ($detail !== null && $detail !== []) {
            $record->detail_json = $detail;
        }

        $record->save();

        return $record;
    }

    /**
     * Añade el resultado de la etapa al `detail` que se persiste.
     *
     * Se descartan valores enormes (texto completo del documento) porque el
     * `detail` va al listado del admin; el texto vive en `attachments`.
     */
    private function detailFromResult(array $detail, mixed $result): array
    {
        if (! is_array($result)) {
            return $detail;
        }

        $trimmed = $result;
        unset($trimmed['text'], $trimmed['raw_request'], $trimmed['raw_response']);

        return $detail + ['result' => $trimmed];
    }

    /**
     * Marca como `skipped` las etapas por adjunto posteriores a un corte.
     *
     * Cuando `detect_kind` falla, `extract_text`/`classify`/`extract_fields`
     * nunca se registran. Sin esto el admin mostraría un timeline con huecos y
     * no se podría distinguir "no se ejecutó" de "se perdió el registro".
     */
    public function markAttachmentSkipped(PipelineStage $from, ?int $attachmentId): void
    {
        foreach (PipelineStage::cases() as $case) {
            if (! $case->isPerAttachment()) {
                continue;
            }

            if ($this->stageOrder($case) <= $this->stageOrder($from)) {
                continue;
            }

            $this->sequence++;
            JobStage::query()->create([
                'email_id'      => $this->email->id,
                'run'           => $this->run,
                'attachment_id' => $attachmentId,
                'stack'         => $this->stack,
                'stage_key'     => $case->value,
                'sequence'      => $this->sequence,
                'status'        => PipelineStatus::Skipped->value,
                'severity'      => 'info',
                'message'       => 'No se ejecutó: el adjunto no pudo analizarse.',
                'finished_at'   => now(),
                'duration_ms'   => 0,
            ]);
        }
    }

    /**
     * Marca como `skipped` todas las etapas posteriores que no corrieron.
     *
     * Se llama cuando el pipeline se corta, para que el timeline no tenga huecos
     * y el admin muestre explícitamente qué no llegó a ejecutarse.
     */
    public function markRemainingAsSkipped(PipelineStage $from): void
    {
        DB::transaction(function () use ($from) {
            foreach (PipelineStage::cases() as $case) {
                if ($this->stageOrder($case) <= $this->stageOrder($from)) {
                    continue;
                }

                $this->sequence++;
                JobStage::query()->create([
                    'email_id'   => $this->email->id,
                    'run'        => $this->run,
                    'stack'      => $this->stack,
                    'stage_key'  => $case->value,
                    'sequence'   => $this->sequence,
                    'status'     => PipelineStatus::Skipped->value,
                    'severity'   => 'info',
                    'message'    => 'No se ejecutó: el pipeline se detuvo antes.',
                    'finished_at' => now(),
                    'duration_ms' => 0,
                ]);
            }
        });
    }

    private function stageOrder(PipelineStage $stage): int
    {
        $order = array_search($stage, PipelineStage::cases(), true);

        return $order === false ? PHP_INT_MAX : $order;
    }
}
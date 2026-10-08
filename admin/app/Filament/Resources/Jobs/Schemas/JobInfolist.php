<?php

namespace App\Filament\Resources\Jobs\Schemas;

use App\Filament\Resources\Jobs\JobResource;
use App\Models\Attachment;
use App\Models\ProcessedEmail;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Página de detalle del job.
 *
 * El bloque central es el TIMELINE DE ETAPAS: una fila por ejecución de etapa,
 * en orden, con su duración y su estado. Es lo que el requerimiento pedía
 * ("un admin sencillo para ver las etapas de análisis de los jobs").
 *
 * Como `job_stages` es append-only, reprocesar agrega un segundo timeline en
 * lugar de reemplazar el anterior: se agrupan por corrida (`sequence` reinicia
 * en cada `pipeline:reprocess`) para que ambos se lean sin mezclarse.
 */
class JobInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Correo')
                    ->columnSpanFull()
                    ->columns([
                        'default' => 1,
                        'sm'      => 2,
                        'lg'      => 4,
                    ])->schema([
                    TextEntry::make('subject')->label('Asunto')
                        ->columnSpan(['default' => 1, 'sm' => 2, 'lg' => 2]),
                    TextEntry::make('sender')->label('Remitente')
                        ->placeholder('—')
                        ->extraAttributes(['class' => 'break-normal']),
                    TextEntry::make('mailbox')->label('Buzón')
                        ->extraAttributes(['class' => 'break-normal']),

                    TextEntry::make('status')->label('Estado')
                        ->badge()
                        ->color(fn (?ProcessedEmail $record): string => JobResource::statusColor($record?->status ?? ''))
                        ->formatStateUsing(fn (string $state): string => JobResource::statusLabel($state)),
                    TextEntry::make('pipeline_stack')->label('Stack')
                        ->badge()
                        ->color(fn (string $state): string => $state === 'b' ? 'info' : 'gray')
                        ->formatStateUsing(fn (string $state): string => $state === 'b' ? 'B · LLM' : 'A · OCR local'),
                    TextEntry::make('received_at')->label('Recibido')->dateTime('d/m/Y H:i'),
                    TextEntry::make('total_duration')->label('Duración total')
                        ->state(fn (?ProcessedEmail $record): string => self::formatMs($record?->totalDurationMs())),
                ]),

                Section::make('Etapas del análisis')
                    ->columnSpanFull()
                    ->description(
                        'Cada nodo es una etapa del pipeline. Clic en un nodo para ver qué hace y descargar el archivo.'
                    )->schema([
                    ViewEntry::make('timeline')
                        ->hiddenLabel()
                        ->columnSpanFull()
                        ->view('filament.jobs.timeline-wrapper'),

                    TextEntry::make('live')
                        ->hiddenLabel()
                        ->columnSpanFull()
                        ->html()
                        ->visible(fn (ProcessedEmail $record): bool => ! $record->isFinished())
                        ->state(fn (ProcessedEmail $record): string => view('filament.jobs.timeline-live', [
                            'record' => $record,
                        ])->render()),
                ]),

                self::attachmentsSection()->columnSpanFull(),
                self::validationSection()->columnSpanFull(),
                self::responseSection()->columnSpanFull(),
            ]);
    }

    // ── Timeline ─────────────────────────────────────────────────────────────

    /**
     * HTML del grafo de etapas.
     *
     * La vista se renderiza UNA VEZ POR CORRIDA en vez de recibir todas juntas:
     * cada corrida es un grafo completo e independiente, y el `use` de PHP que
     * importa `PipelineStage` tiene que quedar en el nivel superior del archivo
     * de la vista — dentro de un `@if` sería un error de parseo.
     *
     * Con `$run = null` la vista dibuja el estado vacío, para no duplicar el
     * "todavía no hay etapas" en PHP y en Blade.
     */
    private static function timelineHtml(ProcessedEmail $email): string
    {
        $runs = self::runsOf($email);

        if ($runs === []) {
            return view('filament.jobs.timeline', [
                'runs' => [],
                'run'  => null,
                'index' => 0,
            ])->render();
        }

        $blocks = [];

        foreach ($runs as $index => $run) {
            $blocks[] = view('filament.jobs.timeline', [
                'email' => $email,
                'runs'  => $runs,
                'run'   => $run,
                'index' => $index,
            ])->render();
        }

        return '<div class="space-y-4">'.implode('', $blocks).'</div>';
    }

    /**
     * Agrupa las etapas por corrida del pipeline.
     *
     * `job_stages.run` es un contador explícito que `StageRecorder` incrementa
     * en cada ejecución. No se agrupa por `sequence` porque esa columna es
     * monotónica a propósito (para que los reintentos queden ordenados), y eso
     * haría que todas las corridas se leyeran como una sola.
     *
     * @return array<int, array{number: int, started: mixed, stack: string, stages: array, degraded: bool, failed: bool, total_ms: int}>
     */
    public static function runsOf(ProcessedEmail $email): array
    {
        $stages = $email->stages()->get();

        if ($stages->isEmpty()) {
            return [];
        }

        $runs = [];

        foreach ($stages->groupBy('run') as $number => $group) {
            $first = $group->first();
            $failed = $group->contains('status', 'failed');

            $runs[] = [
                'number'   => (int) $number,
                'started'  => $first->started_at,
                'stack'    => $first->stack,
                'stages'   => $group->values()->all(),
                'degraded' => $group->contains('status', 'degraded'),
                'failed'   => $failed,
                // Una corrida cortada antes de completarse no tiene duración
                // total real: se marca para que el admin la muestre incompleta.
                'complete' => ! $failed && $group->contains('stage_key', 'done'),
                'total_ms' => (int) $group->sum('duration_ms'),
            ];
        }

        // La corrida más reciente se muestra la primera.
        return array_reverse($runs);
    }

    // ── Secciones ────────────────────────────────────────────────────────────

    private static function attachmentsSection(): Section
    {
        return Section::make('Adjuntos')
            ->description('Archivos detectados, clasificados y extraídos. Clic en cualquier archivo para ver su detalle completo.')
            ->schema([
                ViewEntry::make('attachments_gallery')
                    ->hiddenLabel()
                    ->columnSpanFull()
                    ->view('filament.jobs.attachments-wrapper'),
            ]);
    }

    private static function validationSection(): Section
    {
        return Section::make('Resultados de validación')
            ->description('Una fila por regla evaluada en Fase 2.')
            ->collapsible()
            ->collapsed()
            ->schema([
                RepeatableEntry::make('validationResults')
                    ->hiddenLabel()
                    ->state(fn (ProcessedEmail $record): array => $record->validationResults()->get()->all())
                    ->columns(4)
                    ->schema([
                        TextEntry::make('rule_name')->label('Regla')->weight(2),
                        TextEntry::make('status')->label('Estado')
                            ->badge()
                            ->color(fn (string $state): string => match ($state) {
                                'pass'  => 'success',
                                'warn'  => 'warning',
                                'fail'  => 'danger',
                                'error' => 'danger',
                                default => 'gray',
                            }),
                        TextEntry::make('severity')->label('Severidad')
                            ->badge()
                            ->color(fn (string $state): string => $state === 'error' ? 'danger' : 'warning'),
                        TextEntry::make('code')->label('Código')->placeholder('—'),
                        TextEntry::make('message')->label('Mensaje')->columnSpan(4)->placeholder('—'),
                    ]),
            ]);
    }

    private static function responseSection(): Section
    {
        return Section::make('Respuesta enviada')
            ->description('Con GMAIL_SEND_ENABLED=false queda como dry_run: se generó pero no salió.')
            ->collapsible()
            ->collapsed()
            ->schema([
                RepeatableEntry::make('responses')
                    ->hiddenLabel()
                    ->state(fn (ProcessedEmail $record): array => $record->responses()->get()->all())
                    ->columns(4)
                    ->schema([
                        TextEntry::make('template')->label('Plantilla'),
                        TextEntry::make('source')->label('Origen')
                            ->badge()
                            ->color(fn (string $state): string => $state === 'llm' ? 'info' : 'gray'),
                        TextEntry::make('status')->label('Estado')
                            ->badge()
                            ->color(fn (string $state): string => match ($state) {
                                'sent'    => 'success',
                                'dry_run' => 'warning',
                                'failed'  => 'danger',
                                default   => 'gray',
                            }),
                        TextEntry::make('sent_at')->label('Enviado')->dateTime('d/m/Y H:i')
                            ->placeholder('—'),
                        TextEntry::make('body')->label('Texto')->columnSpan(4),
                    ]),
            ]);
    }

    private static function formatMs(?int $ms): string
    {
        return match (true) {
            $ms === null       => '—',
            $ms < 1000         => $ms.' ms',
            default            => round($ms / 1000, 2).' s',
        };
    }
}
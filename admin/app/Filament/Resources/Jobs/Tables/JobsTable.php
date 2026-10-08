<?php

namespace App\Filament\Resources\Jobs\Tables;

use App\Filament\Resources\Jobs\JobResource;
use App\Models\ProcessedEmail;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Listado de jobs.
 *
 * Las columnas están pensadas para leer de un vistazo si algo va mal:
 * el estado, en qué etapa se quedó, cuánto tardó y cuántos adjuntos salido bien.
 */
class JobsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                self::subjectColumn(),
                self::statusColumn(),
                self::stackColumn(),
                self::stageColumn(),
                self::attachmentsColumn(),
                self::durationColumn(),
                self::receivedColumn(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(ProcessedEmail::TERMINAL_STATUSES)
                        ->push(ProcessedEmail::STATUS_PENDING)
                        ->push(ProcessedEmail::STATUS_PROCESSING)
                        ->mapWithKeys(fn (string $s) => [$s => JobResource::statusLabel($s)]))
                    ->label('Estado'),

                SelectFilter::make('pipeline_stack')
                    ->options(['a' => 'Stack A (OCR local)', 'b' => 'Stack B (LLM)'])
                    ->label('Stack'),

                SelectFilter::make('current_stage')
                    ->options(collect(\App\Enums\PipelineStage::cases())
                        ->mapWithKeys(fn ($stage) => [$stage->value => $stage->label()]))
                    ->label('Etapa'),

                // `state` solo viene en `$data` si el filtro se está aplicando;
                // cuando no está el filtro hay que tratar el valor como null,
                // si no el listado entero revienta con "Undefined array key".
                TernaryFilter::make('has_errors')
                    ->label('¿Tiene errores?')
                    ->query(fn (Builder $query, array $data) => $query
                        ->when(
                            ($data['state'] ?? null) === true,
                            fn ($q) => $q->whereHas(
                                'validationResults',
                                fn ($sub) => $sub->whereIn('status', ['fail', 'error'])
                                    ->where('severity', 'error'),
                            ),
                        )
                        ->when(
                            ($data['state'] ?? null) === false,
                            fn ($q) => $q->whereDoesntHave(
                                'validationResults',
                                fn ($sub) => $sub->whereIn('status', ['fail', 'error'])
                                    ->where('severity', 'error'),
                            ),
                        )),
            ])
            ->recordUrl(fn (ProcessedEmail $record): string => JobResource::getUrl('view', ['record' => $record]));
    }

    private static function subjectColumn(): TextColumn
    {
        return TextColumn::make('subject')
            ->label('Correo')
            ->description(fn (ProcessedEmail $record): string => $record->sender ?? '')
            ->searchable(['subject', 'sender'])
            ->sortable()
            ->limit(48)
            ->tooltip(fn (ProcessedEmail $record): string => (string) $record->subject);
    }

    private static function statusColumn(): TextColumn
    {
        return TextColumn::make('status')
            ->label('Estado')
            ->badge()
            ->color(fn (ProcessedEmail $record): string => JobResource::statusColor($record->status))
            ->formatStateUsing(fn (string $state): string => JobResource::statusLabel($state))
            ->sortable();
    }

    private static function stackColumn(): TextColumn
    {
        return TextColumn::make('pipeline_stack')
            ->label('Stack')
            ->badge()
            ->color(fn (string $state): string => $state === 'b' ? 'info' : 'gray')
            ->formatStateUsing(fn (string $state): string => $state === 'b' ? 'B · LLM' : 'A · OCR')
            ->sortable();
    }

    /** Dónde se quedó. Si hay etapas degradadas, se marca en rojo. */
    private static function stageColumn(): TextColumn
    {
        return TextColumn::make('current_stage')
            ->label('Etapa')
            ->badge()
            ->color(function (ProcessedEmail $record): string {
                if ($record->stages()->where('status', 'degraded')->exists()) {
                    return 'warning';
                }

                return $record->stages()->where('status', 'failed')->exists() ? 'danger' : 'gray';
            })
            ->formatStateUsing(fn (string $state, ProcessedEmail $record): string => $record->stageEnum()?->label() ?? $state)
            ->sortable();
    }

    /** Resumen de adjuntos: cuántos van, cuántos se clasificaron. */
    private static function attachmentsColumn(): TextColumn
    {
        return TextColumn::make('attachments_count')
            ->label('Adjuntos')
            ->counts('attachments')
            ->badge()
            ->color(function (ProcessedEmail $record): string {
                $total = $record->attachments()->count();
                $classified = $record->attachments()
                    ->whereNotNull('doc_type')
                    ->where('doc_type', '!=', 'desconocido')
                    ->count();

                return $classified === $total && $total > 0 ? 'success' : 'warning';
            })
            ->description(function (ProcessedEmail $record): string {
                $counts = $record->classifiedCounts();

                if ($counts === []) {
                    return $record->attachments()->count() === 0
                        ? 'Sin adjuntos'
                        : 'Ninguno clasificado';
                }

                $parts = [];
                foreach ($counts as $type => $total) {
                    $parts[] = $total.' '.$type;
                }

                return implode(' · ', $parts);
            })
            ->sortable();
    }

    /** De la primera a la última etapa. `—` si el job no terminó. */
    private static function durationColumn(): TextColumn
    {
        return TextColumn::make('duration')
            ->label('Duración')
            ->alignEnd()
            ->state(fn (ProcessedEmail $record): ?string => self::formatMs($record->totalDurationMs()))
            ->sortable(['created_at']);
    }

    private static function receivedColumn(): TextColumn
    {
        return TextColumn::make('received_at')
            ->label('Recibido')
            ->dateTime('d/m/Y H:i')
            ->sortable();
    }

    private static function formatMs(?int $ms): string
    {
        if ($ms === null) {
            return '—';
        }

        return $ms < 1000
            ? $ms.' ms'
            : round($ms / 1000, 2).' s';
    }
}
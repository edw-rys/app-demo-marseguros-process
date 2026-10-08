<?php

namespace App\Filament\Widgets;

use App\Models\JobStage;
use App\Models\ProcessedEmail;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Cifras de cabecera del pipeline.
 *
 * Deliberadamente pocas: total, estado terminal y degradación. El detalle fino
 * está en el listado de jobs, filtrable.
 */
class PipelineStatsWidget extends StatsOverviewWidget
{
    protected int|string|array $columnSpan = 'full';

    protected function getStats(): array
    {
        $total = ProcessedEmail::query()->count();

        $validated = ProcessedEmail::query()
            ->where('status', ProcessedEmail::STATUS_VALIDATED)->count();
        $failed = ProcessedEmail::query()
            ->where('status', ProcessedEmail::STATUS_FAILED)->count();
        $review = ProcessedEmail::query()
            ->where('status', ProcessedEmail::STATUS_REVIEW)->count();
        $pending = ProcessedEmail::query()
            ->whereIn('status', [
                ProcessedEmail::STATUS_PENDING,
                ProcessedEmail::STATUS_PROCESSING,
            ])->count();

        $degradedRuns = JobStage::query()
            ->where('status', 'degraded')
            ->distinct('email_id')
            ->count('email_id');

        return [
            Stat::make('Correos', (string) $total)
                ->description('procesados desde el primer sync')
                ->chart([$total]),

            Stat::make('Validados', (string) $validated)
                ->description($total > 0 ? round($validated / $total * 100).'% del total' : '—')
                ->color('success'),

            Stat::make('Con errores', (string) $failed)
                ->description($total > 0 ? round($failed / $total * 100).'% del total' : '—')
                ->color('danger'),

            Stat::make('Revisión manual', (string) $review)
                ->description('el LLM no pudo decidir')
                ->color('warning'),

            Stat::make('Pendientes', (string) $pending)
                ->description('esperan documentos o proceso')
                ->color('info'),

            Stat::make('Degradados', (string) $degradedRuns)
                ->description('jobs con fallback a Stack A')
                ->color($degradedRuns > 0 ? 'warning' : 'gray'),
        ];
    }
}
<?php

namespace App\Filament\Widgets;

use App\Enums\PipelineStage;
use App\Models\JobStage;
use Filament\Widgets\ChartWidget;

/**
 * Embudo por etapa: cuántos jobs llegaron a cada una.
 *
 * Es el widget que responde "¿dónde se cae el sistema?". Una etapa con muchos
 * `failed` es la que hay que mirar; una con muchos `skipped` solo indica que
 * el pipeline se detuvo antes, y hay que mirar la etapa anterior.
 */
class StageFunnelWidget extends ChartWidget
{
    protected ?string $heading = 'Embudo por etapa';

    protected ?string $description = 'Jobs que alcanzaron cada etapa, desglosados por resultado.';

    protected int|string|array $columnSpan = 'full';

    protected ?string $maxHeight = '320px';

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $datasets = [];
        $labels = [];

        foreach (PipelineStage::cases() as $stage) {
            $labels[] = $stage->label();

            foreach (['passed', 'failed', 'degraded', 'skipped'] as $status) {
                $datasets[$status] ??= [];
            }
        }

        $counts = JobStage::query()
            ->selectRaw('stage_key, status, count(*) as total')
            ->groupBy('stage_key', 'status')
            ->get()
            ->groupBy('stage_key');

        foreach (PipelineStage::cases() as $stage) {
            $byStatus = $counts->get($stage->value, collect());

            foreach (['passed', 'failed', 'degraded', 'skipped'] as $status) {
                $row = $byStatus->firstWhere('status', $status);
                $datasets[$status][] = (int) ($row->total ?? 0);
            }
        }

        return [
            'labels'   => $labels,
            'datasets' => array_map(
                fn (string $status, array $data): array => [
                    'label'           => ucfirst($status),
                    'data'            => $data,
                    'backgroundColor' => $this->colorFor($status),
                    'borderColor'     => $this->colorFor($status),
                ],
                array_keys($datasets),
                $datasets,
            ),
        ];
    }

    private function colorFor(string $status): string
    {
        return match ($status) {
            'passed'   => '#22c55e',
            'failed'   => '#ef4444',
            'degraded' => '#f59e0b',
            'skipped'  => '#9ca3af',
            default    => '#6b7280',
        };
    }
}
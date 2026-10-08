<?php

namespace App\Filament\Widgets;

use App\Models\LlmCall;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Costo y latencia del LLM (Stack B).
 *
 * RNF-04 pide que la traza sea auditable y RNF-05 que el costo por token sea
 * conocido. Sin este widget nadie mira `llm_calls` hasta que la factura llega.
 */
class LlmCostWidget extends StatsOverviewWidget
{
    protected int|string|array $columnSpan = 'full';

    protected function getStats(): array
    {
        $today = LlmCall::query()
            ->whereDate('created_at', today())
            ->where('degraded', false);

        $costToday = (float) $today->sum('cost_usd');
        $callsToday = (int) $today->count();
        $tokensToday = (int) $today->sum('prompt_tokens') + (int) $today->sum('completion_tokens');
        $avgLatency = (int) $today->avg('latency_ms');

        $failovers = LlmCall::query()->where('degraded', true)->count();
        $cacheHits = LlmCall::query()->where('cache_hit', true)->count();

        return [
            Stat::make('Costo LLM hoy', '$'.number_format($costToday, 4))
                ->description($callsToday.' llamada(s)')
                ->descriptionIcon('heroicon-o-banknotes'),

            Stat::make('Tokens hoy', number_format($tokensToday))
                ->description('prompt + completion')
                ->descriptionIcon('heroicon-o-chart-bar'),

            Stat::make('Latencia media', $avgLatency > 0 ? $avgLatency.' ms' : '—')
                ->description('por llamada')
                ->descriptionIcon('heroicon-o-clock'),

            Stat::make('Fallbacks a Stack A', (string) $failovers)
                ->description('CU-05 · OpenRouter caído')
                ->descriptionIcon('heroicon-o-arrow-uturn-left')
                ->color($failovers > 0 ? 'warning' : 'gray'),

            Stat::make('Cache hits', (string) $cacheHits)
                ->description('F-12 · sin volver a cobrar tokens')
                ->descriptionIcon('heroicon-o-bolt')
                ->color($cacheHits > 0 ? 'success' : 'gray'),
        ];
    }
}
<?php

namespace App\Filament\Widgets;

use App\Services\Pipeline\AnalyzerClient;
use Filament\Widgets\Widget;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;

/**
 * Estado de los dos workers Python.
 *
 * RNF-07 pide que el fallback sea observable. Si Stack B está caído y no se ve
 * aquí, el único indicio es que los jobs salen "degradados", y eso se descubre
 * tarde. Se cachea 15 s: `/healthz` es barato, pero la página se refresca
 * seguido y cada consulta es una llamada de red.
 */
class WorkerHealthWidget extends Widget
{
    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    public function render(): View
    {
        return view('filament.widgets.worker-health', [
            'health' => $this->health(),
            'gmailConfigured' => \App\Services\Gmail\GmailClient::isConfigured(),
            'gmailMode' => \App\Services\Gmail\GmailAuthMode::current(),
            'sendEnabled' => (bool) config('gmail_docs.send_enabled'),
            'defaultStack' => config('gmail_docs.default_stack'),
        ]);
    }

    /** @return array<string, array{up: bool, detail: mixed}> */
    private function health(): array
    {
        return Cache::remember('worker_health', 15, fn () => AnalyzerClient::health());
    }
}
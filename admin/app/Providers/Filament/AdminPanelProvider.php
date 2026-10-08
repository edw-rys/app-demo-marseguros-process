<?php

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Assets\Css;
use Filament\View\PanelsRenderHook;
use Filament\Support\Assets\Js;
use Filament\Support\Colors\Color;
use Filament\Widgets\AccountWidget;
use App\Filament\Widgets\LlmCostWidget;
use App\Filament\Widgets\PipelineStatsWidget;
use App\Filament\Widgets\StageFunnelWidget;
use App\Filament\Widgets\WorkerHealthWidget;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Foundation\Vite;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->colors([
                // #038fac — el teal de Marseguros (CLAUDE.md §15).
                'primary' => Color::hex('#038fac'),
            ])
            // CSS del proyecto (Tailwind compilado por Vite).
            //
            // NO va por `->assets([...])`, y NO se usa `viteTheme()`:
            //
            //  · `viteTheme()` REEMPLAZA el theme por defecto, y ese theme es
            //    el que sirve `/css/filament/filament/app.css` — todo el CSS
            //    de Filament. Con él el panel abre sin una hoja de estilo.
            //  · `Css::make('x')->html(...)` sin `path` rompe el build de la
            //    imagen: `php artisan filament:assets` (que corre en el
            //    `post-autoload-cmd` del Dockerfile) itera los assets de estilo
            //    y llama `copyAsset($asset->getPath(), …)`. Con `path` en null
            //    eso es un TypeError y el build muere con
            //    "Argument #1 ($from) must be of type string, null given".
            //
            // Por eso va por un render hook, que solo emite la etiqueta y no
            // toca el registro de assets. `Vite` resuelve solo: usa
            // `public/hot` si el server está levantado, y
            // `public/build/manifest.json` si no — por eso no hay ningún
            // nombre con hash escrito acá.
            ->renderHook(
                PanelsRenderHook::STYLES_AFTER,
                fn (): Htmlable => app(Vite::class)('resources/css/app.css'),
            )
            ->assets([
                Css::make('gdv-pipeline', asset('css/gdv-pipeline.css')),
                Js::make('gdv-pipeline', asset('js/gdv-pipeline.js')),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
                WorkerHealthWidget::class,
                PipelineStatsWidget::class,
                StageFunnelWidget::class,
                LlmCostWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}

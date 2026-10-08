<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // ── Proxies confidados ───────────────────────────────────────────────
        // Cloudflare termina el TLS y le habla al origen por HTTP. Sin esto,
        // Laravel cree que la petición llegó en `http` y todo lo que se arma
        // con `url()` sale en http: los redirects, los enlaces de Livewire, los
        // emails y el `redirect_uri` de OAuth. En el navegador eso se ve como
        // "mixed content" bloqueado por el navegador.
        //
        // El síntoma más visible es Livewire: `FrontendAssets.php:221` hace
        // `$updateUri = url(...)` UNA vez y lo mete en `data-update-uri`. Si
        // eso sale en `http`, cada interacción posterior (login, guardar,
        // paginar) falla con "blocked: must be served over HTTPS" y la página
        // queda medio muerta.
        //
        // `*` confía en el header que llegue. Es aceptable siempre que SOLO
        // Cloudflare pueda alcanzar el puerto 8080 del contenedor: si el origen
        // está expuesto a internet, alguien podría mandar `X-Forwarded-Proto`
        // falso. Para cerrarlo del todo, poner acá las IPs de Cloudflare.
        //
        // Se lee de `$_SERVER` y NO con `env()` a propósito: `bootstrap/app.php`
        // se incluye en `public/index.php:18`, antes de que corran los
        // bootstrappers — entre ellos `LoadEnvironmentVariables`. O sea que acá
        // `env('TRUSTED_PROXIES')` devolvería SIEMPRE el default, y el valor
        // puesto en el `.env` no se vería nunca. Leyendo `$_SERVER` sí funciona,
        // y esa variable se puede setear en el `environment:` del
        // `docker-compose.yml` o en el entorno del contenedor.
        $middleware->trustProxies(
            at: $_SERVER['TRUSTED_PROXIES'] ?? $_ENV['TRUSTED_PROXIES'] ?? '*',
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO
                | Request::HEADER_X_FORWARDED_PREFIX
                | Request::HEADER_X_FORWARDED_AWS_ELB,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();

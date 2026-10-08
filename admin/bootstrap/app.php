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
            // `HEADER_X_FORWARDED_PORT` está DELIBERADAMENTE ausente, y no es
            // un olvido.
            //
            // Cloudflare manda `X-Forwarded-Port: 80` porque la conexión con
            // el origen es HTTP. Al declararlo confiable, Symfony sobrescribe
            // el puerto y arma URLs como:
            //
            //     https://demos-marseguros.edw-dev.com:80/css/filament/...app.css
            //
            // eso es HTTPS sobre el puerto 80: el handshake TLS no se puede
            // hacer, el asset no carga nunca, y como `app.js` importa
            // `livewire.js` antes de ejecutarse, TODOS los scripts del panel
            // quedan colgados detrás. El HTML llega igual, porque lo sirve la
            // misma capa — por eso el síntoma es "la página carga pero no
            // funciona".
            //
            // Medido contra `Request::setTrustedProxies()`:
            //
            //     con    X-Forwarded-Port confiable → https://host:80
            //     sin    X-Forwarded-Port confiable → https://host
            //
            // El esquema sale de `X-Forwarded-Proto` (que sí va) y el puerto
            // correctamente se omite.
            //
            // OJO con `HEADER_X_FORWARDED_AWS_ELB`: parece inocua pero vale
            // `0b0011010`, y ese número YA incluye el bit del puerto
            // (FOR=2 + PROTO=8 + PORT=16). Con solo esa constante el `:80`
            // volvía a aparecer, y el bitmask escrito a mano es la única
            // forma de dejarlo afuera.
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PROTO
                | Request::HEADER_X_FORWARDED_PREFIX,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();

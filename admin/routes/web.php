<?php

use App\Http\Controllers\AttachmentDownloadController;
use App\Http\Controllers\GmailOAuthController;
use App\Http\Controllers\JobStreamController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/*
|--------------------------------------------------------------------------
| OAuth 2.0 de Gmail (cuenta propia, sin Google Workspace)
|--------------------------------------------------------------------------
|
| Ambas rutas son GET, y el middleware de CSRF de Laravel 13 no valida verbos
| de lectura (`PreventRequestForgery::isReading()`), así que el callback que
| dispara Google no da 419. Lo que protege el `code` es el `state`, que el
| controller genera en /redirect y consume en /callback con `pull()`.
|
| El middleware de autenticación NO es opcional: sin él, cualquiera que
| encontrara la URL podría disparar el flujo OAuth. Es el de Filament y no el
| alias `auth` de Laravel a propósito: el alias redirige a `route('login')`,
| que esta app no tiene (el login vive en el panel, como
| `filament.admin.auth.login`), así que un visitante sin sesión se comería un
| 500 en vez de un redirect al login.
|
*/

Route::middleware(Filament\Http\Middleware\Authenticate::class)
    ->prefix('gmail/oauth')
    ->name('gmail.oauth.')
    ->group(function (): void {
        Route::get('redirect', [GmailOAuthController::class, 'redirect'])->name('redirect');
        Route::get('callback', [GmailOAuthController::class, 'callback'])->name('callback');
    });

/*
|--------------------------------------------------------------------------
| SSE del pipeline en vivo
|--------------------------------------------------------------------------
|
| El JS del detalle de un job abre `EventSource` contra esta ruta para ver las
| etapas conforme las escribe el worker. No es una API REST: la respuesta nunca
| "termina" hasta que el job termina, y por eso necesita el bloque de nginx de
| `docker/nginx.conf` (buffering off) para que los eventos lleguen de a uno.
|
| El middleware NO es opcional por lo mismo que en OAuth: el stream emite el
| asunto del correo y los errores de las etapas. Es el de Filament, no el alias
| `auth` de Laravel —ese redirige a `route('login')`, que esta app no tiene.
|
*/

Route::middleware(Filament\Http\Middleware\Authenticate::class)
    ->get('jobs/{uuid}/stream', JobStreamController::class)
    ->name('jobs.stream');

/*
|--------------------------------------------------------------------------
| Descarga de adjuntos
|--------------------------------------------------------------------------
|
| Los adjuntos no se sirven por `public/storage` — `storage/app/attachments`
| no es público a propósito: son documentos de seguro que llegan de un remitente
| externo. El enlace de descarga del detalle del job va a esta ruta.
|
| El middleware es el mismo de las otras dos: sin sesión de panel, cualquiera
| que conozca la URL descarga los adjuntos de cualquier job.
|
*/

use App\Http\Controllers\DownloadAllAttachmentsController;

Route::middleware(Filament\Http\Middleware\Authenticate::class)
    ->get('attachments/{uuid}/download', AttachmentDownloadController::class)
    ->name('attachments.download');

Route::middleware(Filament\Http\Middleware\Authenticate::class)
    ->get('jobs/{uuid}/attachments/download-all', DownloadAllAttachmentsController::class)
    ->name('jobs.attachments.download-all');
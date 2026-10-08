<?php

namespace App\Http\Controllers;

use App\Services\Gmail\GmailAuthMode;
use App\Services\Gmail\OAuthClientFactory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * OAuth 2.0 de Gmail para una cuenta propia (RF-01, sin Google Workspace).
 *
 * Son dos GET. El middleware de CSRF de Laravel 13 (`PreventRequestForgery`)
 * no valida verbos de lectura —`isReading()` devuelve `true` para GET—, así
 * que el callback que dispara Google NO da 419 y no hay que exceptuar nada de
 * CSRF. Lo que ata el `code` a esta sesión es el `state`, y por eso se genera y
 * se consume acá, no en Google.
 */
class GmailOAuthController extends Controller
{
    /** Clave de sesión donde vive el `state` pendiente de usar. */
    private const SESSION_KEY = 'gmail.oauth.pending_state';

    public function redirect(OAuthClientFactory $factory): RedirectResponse
    {
        if (! GmailAuthMode::current()->authorizesAMailbox()) {
            return $this->backWithError(
                'El modo OAuth no está activo. Poné GMAIL_AUTH_MODE=oauth en el .env.'
            );
        }

        $state = Str::random(40);

        request()->session()->put(self::SESSION_KEY, $state);

        return redirect()->away($factory->authorizationUrl($state));
    }

    public function callback(Request $request, OAuthClientFactory $factory): RedirectResponse
    {
        // `pull`, no `get`: el state es de un solo uso. Si se pudiera reusar,
        // un `code` capturado quedaría reproducible.
        $expected = (string) $request->session()->pull(self::SESSION_KEY);
        $received = (string) $request->query('state');

        if ($expected === '' || $received === '' || ! hash_equals($expected, $received)) {
            return $this->backWithError(
                'El state de la autorización no coincide: no se guardó nada. Suele '
                .'pasar si el enlace se abrió en otro navegador o en incógnito, o '
                .'si la sesión caducó. Vuelve a pulsar el botón de autorizar.'
            );
        }

        if ($request->filled('error')) {
            return $this->backWithStatus('Cancelaste la autorización en Google.');
        }

        $code = (string) $request->query('code');

        if ($code === '') {
            return $this->backWithError('Google volvió sin código de autorización.');
        }

        try {
            $token = $factory->exchangeCode($code);
        } catch (\Throwable $e) {
            Log::warning('Fallo el intercambio del código OAuth de Gmail', [
                'error' => $e->getMessage(),
            ]);

            return $this->backWithError('No se pudo completar la autorización: '.$e->getMessage());
        }

        return $this->backWithStatus('Gmail conectado como '.$token['mailbox'].'.');
    }

    private function backWithStatus(string $message): RedirectResponse
    {
        return redirect()
            ->route('filament.admin.pages.gmail-connection')
            ->with('gmail_status', $message);
    }

    private function backWithError(string $message): RedirectResponse
    {
        return redirect()
            ->route('filament.admin.pages.gmail-connection')
            ->with('gmail_error', $message);
    }
}
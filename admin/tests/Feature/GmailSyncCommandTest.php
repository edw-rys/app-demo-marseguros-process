<?php

namespace Tests\Feature;

use App\Services\Gmail\OAuthTokenStore;
use Tests\TestCase;

/**
 * El modo silencioso de `gmail:sync`, que es lo que usa el bucle de polling.
 *
 * El bucle de `gmail-poll` en `docker/supervisord.conf` corre este comando cada
 * `GMAIL_POLL_INTERVAL` segundos, para siempre. Sin `--skip-if-unconfigured`, un
 * contenedor recién levantado sin credenciales escribiría el mismo error de
 * "Gmail no está configurado" cada 120 segundos, para siempre, y el log dejaría
 * de servir para diagnosticar nada — se aprende a ignorarlo justo cuando uno lo
 * necesita.
 *
 * Por eso el modo silencioso existe, y por eso el código de salida sigue siendo
 * `FAILURE`: un script que dependa del código no cambia de comportamiento por
 * el flag, que solo controla qué se imprime.
 */
class GmailSyncCommandTest extends TestCase
{
    private string $tokenPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tokenPath = sys_get_temp_dir().'/gdv-sync-'.uniqid().'.json';

        config([
            'gmail_docs.google.auth_mode'       => 'oauth',
            'gmail_docs.google.oauth.token_path' => $this->tokenPath,
        ]);
    }

    protected function tearDown(): void
    {
        if (is_file($this->tokenPath)) {
            unlink($this->tokenPath);
        }

        parent::tearDown();
    }

    /**
     * Sin credenciales y con el flag, el comando NO imprime el error.
     *
     * Este es el caso que llena el bucle de polling: sale limpio y vuelve a
     * dormir, sin ensuciar el log.
     */
    public function test_el_flag_silencia_el_error_de_gmail_no_configurado(): void
    {
        $this->artisan('gmail:sync --skip-if-unconfigured')
            // Mismo código que sin el flag: el flag cambia la verbosidad, no el
            // resultado. Ver la docblock de la clase.
            ->assertExitCode(1);

        // Y eso es justamente lo que importa: el mensaje NO aparece.
        $this->artisan('gmail:sync --skip-if-unconfigured')
            ->doesntExpectOutputToContain('no está autorizado');
    }

    /**
     * El error sigue apareciendo cuando se corre a mano, sin el flag.
     *
     * El caso contrario también importa: si el flag silenciara siempre,
     * alguien que configure mal el `.env` no vería nunca el motivo.
     */
    public function test_sin_el_flag_el_error_se_muestra(): void
    {
        $this->artisan('gmail:sync')
            ->expectsOutputToContain('no está autorizado')
            ->assertExitCode(1);
    }

    /**
     * Autorizar la cuenta y volver a correrlo: el bucle no necesita reiniciarse.
     *
     * Es la propiedad que hace que el modo silencioso sea usable en vez de una
     * trampa: si el flag dejara el comando en `FAILURE` para siempre, habría que
     * reiniciar el contenedor después de autorizar, que es justo lo que una demo
     * en vivo no puede hacer a mitad de una presentación.
     */
    public function test_tras_autorizar_deja_de_silenciarse(): void
    {
        app(OAuthTokenStore::class)->write([
            'access_token'  => 'fake-access-token',
            'refresh_token' => 'fake-refresh-token',
            'expires_in'    => 3600,
            'created'       => time(),
        ]);

        $this->artisan('gmail:sync')
            // Ya no dice que no está configurado. Lo que haga después depende de
            // la red, así que solo se comprueba que el mensaje de configuración
            // desapareció.
            ->doesntExpectOutputToContain('no está autorizado');
    }
}
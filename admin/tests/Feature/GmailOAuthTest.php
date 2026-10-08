<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Gmail\GmailAuthMode;
use App\Services\Gmail\GmailClient;
use App\Services\Gmail\OAuthClientFactory;
use App\Services\Gmail\OAuthTokenStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Route;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * El flujo OAuth de Gmail (RF-01) en modo cuenta personal.
 *
 * Nada de aquí toca la red. El intercambio del `code` es lo único que habla con
 * Google, y va detrás de `OAuthClientFactory`, que se sustituye por un doble: se
 * podría intentar con `Http::fake()`, pero NO funciona — `google/apiclient`
 * construye su propio cliente Guzzle en `Client::createDefaultHttpClient()` en
 * lugar de usar el de Laravel, así que la fachada no lo intercepta.
 *
 * El resto —rutas, `state`, `error`, resolución de buzones e
 * `isConfigured()`— es lógica pura.
 */
class GmailOAuthTest extends TestCase
{
    use RefreshDatabase;

    private string $tokenPath;

    protected function setUp(): void
    {
        parent::setUp();

        // El token va a un temporal por test: escribir en el de verdad dejaría
        // el entorno de desarrollo en un estado distinto al que se encontró, y
        // los tests tienen que poder correr en cualquier orden.
        $this->tokenPath = sys_get_temp_dir().'/gdv-oauth-'.uniqid().'.json';

        config([
            'gmail_docs.google.auth_mode' => 'oauth',
            'gmail_docs.google.oauth.token_path' => $this->tokenPath,
            'gmail_docs.google.oauth.redirect_uri' => 'http://localhost:8000/gmail/oauth/callback',
            'gmail_docs.google.oauth.scopes' => ['https://www.googleapis.com/auth/gmail.readonly'],
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
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function fakeToken(array $overrides = []): array
    {
        return $overrides + [
            'mailbox'       => 'demo@gmail.com',
            'access_token'  => 'ya29.access',
            'refresh_token' => '1//refresh',
            'expires_in'    => 3600,
            'created'       => time(),
            'scopes'        => ['https://www.googleapis.com/auth/gmail.readonly'],
            'authorized_at' => now()->toIso8601String(),
        ];
    }

    private function admin(): User
    {
        $user = User::factory()->create(['email' => 'panel@demo.local']);
        $this->actingAs($user);

        return $user;
    }

    // ── Rutas ──────────────────────────────────────────────────────────────

    public function test_las_dos_rutas_de_oauth_estan_registradas(): void
    {
        $this->assertTrue(Route::has('gmail.oauth.redirect'));
        $this->assertTrue(Route::has('gmail.oauth.callback'));
    }

    public function test_sin_sesion_del_panel_el_redirect_no_dispara_oauth(): void
    {
        // El middleware protege el flujo: sin él, cualquiera que encontrara la
        // URL podría autorizar una cuenta desde internet. Se comprueba que
        // NO se llega a Google — que la respuesta sea un redirect al login del
        // panel, no un 302 a accounts.google.com.
        $this->get(route('gmail.oauth.redirect'))
            ->assertRedirect(Filament::getLoginUrl());
    }

    // ── state ──────────────────────────────────────────────────────────────

    public function test_un_state_que_no_corresponde_se_rechaza(): void
    {
        $this->admin();

        $this->withSession(['gmail.oauth.pending_state' => 'el-state-bueno'])
            ->get(route('gmail.oauth.callback', ['code' => 'x', 'state' => 'otro']))
            ->assertRedirect(route('filament.admin.pages.gmail-connection'))
            ->assertSessionHas('gmail_error');

        $this->assertFalse(
            app(OAuthTokenStore::class)->exists(),
            'Un state inválido no debe dejar token escrito en disco.'
        );
    }

    public function test_sin_state_en_sesion_se_rechaza_aunque_llegue_uno_en_la_url(): void
    {
        $this->admin();

        $this->get(route('gmail.oauth.callback', ['code' => 'x', 'state' => 'inventado']))
            ->assertRedirect(route('filament.admin.pages.gmail-connection'))
            ->assertSessionHas('gmail_error');

        $this->assertFalse(app(OAuthTokenStore::class)->exists());
    }

    public function test_el_state_es_de_un_solo_uso(): void
    {
        $this->admin();

        // `once()` es la mitad de la prueba: si el segundo callback llegara a
        // intercambiar el código, Mockery lo marcaría como violación.
        $this->mock(OAuthClientFactory::class, function (MockInterface $mock): void {
            $mock->shouldReceive('exchangeCode')->once()->andReturn($this->fakeToken());
        });

        $params = ['code' => 'el-code', 'state' => 'el-state'];

        $this->withSession(['gmail.oauth.pending_state' => 'el-state'])
            ->get(route('gmail.oauth.callback', $params))
            ->assertRedirect()
            ->assertSessionHas('gmail_status');

        // El mismo `code` reenviado ya no sirve. Sin volver a sembrar la
        // sesión: el `state` sigue ahí, pero `pull()` ya lo consumió, que es
        // exactamente el replay que hay que rechazar.
        $this->get(route('gmail.oauth.callback', $params))
            ->assertRedirect()
            ->assertSessionHas('gmail_error');
    }

    // ── Errores de Google ──────────────────────────────────────────────────

    public function test_el_usuario_que_cancela_en_google_no_es_un_error(): void
    {
        $this->admin();

        $this->withSession(['gmail.oauth.pending_state' => 's'])
            ->get(route('gmail.oauth.callback', ['error' => 'access_denied', 'state' => 's']))
            ->assertRedirect()
            ->assertSessionHas('gmail_status');
    }

    public function test_un_callback_sin_code_se_gestiona(): void
    {
        $this->admin();

        $this->withSession(['gmail.oauth.pending_state' => 's'])
            ->get(route('gmail.oauth.callback', ['state' => 's']))
            ->assertRedirect()
            ->assertSessionHas('gmail_error');
    }

    public function test_un_intercambio_fallido_no_deja_token_a_medias(): void
    {
        $this->admin();

        $this->mock(OAuthClientFactory::class, function (MockInterface $mock): void {
            $mock->shouldReceive('exchangeCode')
                ->andThrow(new \RuntimeException('Google no devolvió refresh_token.'));
        });

        $this->withSession(['gmail.oauth.pending_state' => 's'])
            ->get(route('gmail.oauth.callback', ['code' => 'c', 'state' => 's']))
            ->assertRedirect()
            ->assertSessionHas('gmail_error');

        $this->assertFalse(app(OAuthTokenStore::class)->exists());
    }

    public function test_autorizar_fuera_de_modo_oauth_no_pide_nada_a_google(): void
    {
        $this->admin();

        config(['gmail_docs.google.auth_mode' => 'service_account']);

        // Sin el mock, si el controller intentara armar la URL real, la librería
        // de Google fallaría al no encontrar el JSON del cliente.
        $this->get(route('gmail.oauth.redirect'))
            ->assertRedirect(route('filament.admin.pages.gmail-connection'))
            ->assertSessionHas('gmail_error');
    }

    // ── Resolución de buzones por modo ────────────────────────────────────

    public function test_en_modo_oauth_el_buzon_es_el_del_token(): void
    {
        app(OAuthTokenStore::class)->write($this->fakeToken());

        $this->assertSame(['demo@gmail.com'], app(GmailClient::class)->mailboxes());
    }

    public function test_en_modo_oauth_sin_token_no_hay_buzones(): void
    {
        $this->assertSame([], app(GmailClient::class)->mailboxes());
    }

    public function test_en_modo_service_account_los_buzones_siguen_siendo_los_del_env(): void
    {
        config([
            'gmail_docs.google.auth_mode' => 'service_account',
            'gmail_docs.google.mailboxes' => ['docs@dominio.com', 'seguros@dominio.com'],
        ]);

        $this->assertSame(
            ['docs@dominio.com', 'seguros@dominio.com'],
            app(GmailClient::class)->mailboxes(),
        );
    }

    // ── isConfigured() por modo ────────────────────────────────────────────

    public function test_service_account_sigue_pidiendo_lo_de_siempre(): void
    {
        config([
            'gmail_docs.google.auth_mode' => 'service_account',
            'gmail_docs.google.service_account_path' => '/no/existe.json',
            'gmail_docs.google.delegated_user' => null,
            'gmail_docs.google.mailboxes' => [],
        ]);

        $this->assertFalse(GmailClient::isConfigured());
    }

    public function test_service_account_configurado_sigue_dando_true(): void
    {
        // La ruta DWD no puede cambiar de comportamiento con este cambio.
        config([
            'gmail_docs.google.auth_mode' => 'service_account',
            'gmail_docs.google.service_account_path' => $this->writeTempCredentials(),
            'gmail_docs.google.delegated_user' => 'watcher@dominio.com',
            'gmail_docs.google.mailboxes' => ['docs@dominio.com'],
        ]);

        $this->assertTrue(GmailClient::isConfigured());
    }

    public function test_oauth_sin_token_no_esta_configurado(): void
    {
        $this->assertFalse(GmailClient::isConfigured());
    }

    public function test_oauth_con_token_esta_configurado(): void
    {
        app(OAuthTokenStore::class)->write($this->fakeToken());

        $this->assertTrue(GmailClient::isConfigured());
    }

    public function test_un_token_sin_refresh_token_no_cuenta_como_configurado(): void
    {
        // Sin refresh token el sistema no sobrevive a la caducidad del access
        // token: contarlo como configurado haría que el sync fallara más tarde
        // con un error de red, en vez de un aviso de "autoriza la cuenta".
        app(OAuthTokenStore::class)->write($this->fakeToken(['refresh_token' => '']));

        $this->assertFalse(GmailClient::isConfigured());
    }

    // ── Scope de solo lectura ──────────────────────────────────────────────

    public function test_con_scope_de_solo_lectura_no_se_puede_enviar(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/solo lectura/');

        app(GmailClient::class)->assertCanSend();
    }

    public function test_con_el_scope_de_service_account_si_se_puede_enviar(): void
    {
        config(['gmail_docs.google.auth_mode' => 'service_account']);

        app(GmailClient::class)->assertCanSend();

        $this->assertTrue(app(GmailClient::class)->canSend());
    }

    // ── Almacenamiento del token ────────────────────────────────────────────

    public function test_el_token_se_escribe_con_permisos_restringidos(): void
    {
        $store = app(OAuthTokenStore::class);
        $store->write($this->fakeToken());

        $this->assertFileExists($this->tokenPath);
        $this->assertStringContainsString('"refresh_token"', (string) file_get_contents($this->tokenPath));

        // 0600: mismo criterio que el JSON de la service account.
        $this->assertSame('0600', substr(sprintf('%o', fileperms($this->tokenPath)), -4));
    }

    public function test_leer_el_token_conserva_el_buzon(): void
    {
        $store = app(OAuthTokenStore::class);
        $store->write($this->fakeToken());

        $this->assertSame('demo@gmail.com', $store->mailbox());
        $this->assertNotNull($store->expiresAt());
        $this->assertNotNull($store->authorizedAt());

        $store->forget();

        $this->assertFalse($store->exists());
        $this->assertNull($store->mailbox());
    }

    // ── Modo ───────────────────────────────────────────────────────────────

    public function test_el_modo_por_defecto_no_le_cambia_la_config_a_nadie(): void
    {
        // Sin `auth_mode` en la config, el comportamiento es el de siempre.
        config(['gmail_docs.google.auth_mode' => null]);

        $this->assertSame(GmailAuthMode::ServiceAccount, GmailAuthMode::current());
    }

    /** @return string Ruta de un JSON de credenciales descartable. */
    private function writeTempCredentials(): string
    {
        $path = sys_get_temp_dir().'/gdv-sa-'.uniqid().'.json';

        file_put_contents($path, json_encode([
            'type'         => 'service_account',
            'client_email' => 'bot@demo.iam.gserviceaccount.com',
            'private_key'  => '-----BEGIN PRIVATE KEY-----x-----END PRIVATE KEY-----',
        ]));

        $this->beforeApplicationDestroyed(fn () => is_file($path) && unlink($path));

        return $path;
    }
}
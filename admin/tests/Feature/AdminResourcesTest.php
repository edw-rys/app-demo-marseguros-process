<?php

namespace Tests\Feature;

use App\Filament\Pages\GmailConnection;
use App\Filament\Resources\Attachments\AttachmentResource;
use App\Filament\Resources\DocTypeRules\DocTypeRuleResource;
use App\Filament\Resources\ValidationPrompts\Schemas\ValidationPromptForm;
use App\Filament\Resources\EmailResponses\EmailResponseResource;
use App\Filament\Resources\FieldPatterns\FieldPatternResource;
use App\Filament\Resources\Jobs\JobResource;
use App\Filament\Resources\LlmCalls\LlmCallResource;
use App\Filament\Resources\ValidationPrompts\ValidationPromptResource;
use App\Filament\Resources\ValidationResults\ValidationResultResource;
use App\Filament\Resources\ValidationRules\ValidationRuleResource;
use App\Filament\Resources\WatchStates\WatchStateResource;
use App\Models\Attachment;
use App\Models\DocTypeRule;
use App\Models\EmailResponse;
use App\Models\FieldPattern;
use App\Models\ProcessedEmail;
use App\Models\ValidationPrompt;
use App\Models\ValidationResult;
use App\Models\ValidationRule;
use App\Models\User;
use App\Models\WatchState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cada pantalla del admin tiene que ABRIR, no solo registrar su ruta.
 *
 * `route:list` pasa aunque una columna apunte a un atributo inexistente o un
 * formulario use una clase que ya no existe en esta versión de Filament: el
 * error sale recién al renderizar. Estos tests renderizan todas.
 */
class AdminResourcesTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $user = User::factory()->create(['email' => 'panel@demo.local']);

        $this->actingAs($user);

        return $user;
    }

    // ── Listados de solo lectura ───────────────────────────────────────────

    public function test_listado_de_adjuntos_arranca_vacio(): void
    {
        $this->admin();

        $this->get(AttachmentResource::getUrl('index'))->assertSuccessful();
    }

    public function test_listado_de_adjuntos_arranca_con_datos(): void
    {
        $this->admin();

        $email = ProcessedEmail::factory()->create();
        Attachment::factory()->forEmail($email)->classified('factura', ['total' => '120'], 0.92)->create();

        $this->get(AttachmentResource::getUrl('index'))->assertSuccessful();
    }

    public function test_listado_de_resultados_arranca(): void
    {
        $this->admin();

        $email = ProcessedEmail::factory()->create();
        ValidationResult::factory()->create(['email_id' => $email->id]);

        $this->get(ValidationResultResource::getUrl('index'))->assertSuccessful();
    }

    public function test_listado_de_llamadas_al_llm_arranca(): void
    {
        $this->admin();

        $this->get(LlmCallResource::getUrl('index'))->assertSuccessful();
    }

    public function test_listado_de_respuestas_arranca(): void
    {
        $this->admin();

        $this->get(EmailResponseResource::getUrl('index'))->assertSuccessful();
    }

    public function test_listado_de_buzones_arranca_vacio(): void
    {
        $this->admin();

        $this->get(WatchStateResource::getUrl('index'))->assertSuccessful();
    }

    public function test_las_columnas_de_error_no_se_esconden_del_listado(): void
    {
        // Filament evalúa `visible()` al mapear columnas, antes de que exista
        // alguna fila. Una closure tipo `visible(fn ($r) => $r->error !== null)`
        // devuelve false ahí y la columna desaparece del listado entero: el
        // error queda invisible justo cuando hay que mirarlo.
        $this->admin();

        $email = ProcessedEmail::factory()->create();
        $attachment = Attachment::factory()->forEmail($email)->create();

        $attachment->update(['processing_error' => 'OCR falló de forma']);
        WatchState::query()->create([
            'user_email'   => 'docs@demo.local',
            'history_id'   => '99',
            'last_error'   => 'Credenciales invalidas',
            'last_poll_at' => now()->subDay(),
        ]);
        EmailResponse::query()->create([
            'email_id' => $email->id,
            'template' => 'issues',
            'body'     => 'Falta la factura.',
            'status'   => 'failed',
            'error'    => 'No se pudo enviar',
        ]);

        $this->get(AttachmentResource::getUrl('index'))
            ->assertSuccessful()
            ->assertSee('OCR falló de forma', escape: false);

        $this->get(WatchStateResource::getUrl('index'))
            ->assertSuccessful()
            ->assertSee('Credenciales invalidas');

        $this->get(EmailResponseResource::getUrl('index'))
            ->assertSuccessful()
            ->assertSee('No se pudo enviar');
    }

    public function test_listado_de_buzones_muestra_el_ultimo_error(): void
    {
        $this->admin();

        WatchState::query()->create([
            'user_email'   => 'docs@demo.local',
            'history_id'   => '99',
            'last_error'   => 'Credenciales inválidas',
            'last_poll_at' => now()->subDay(),
        ]);

        $this->get(WatchStateResource::getUrl('index'))
            ->assertSuccessful()
            // `escape: false`: la columna viene acentuada y assertSee
            // escaparía el HTML antes de comparar.
            ->assertSee('Credenciales inválidas', escape: false);
    }

    // ── Formularios editables ──────────────────────────────────────────────

    public function test_formulario_de_patron_de_campo_renderiza(): void
    {
        $this->admin();

        $pattern = FieldPattern::query()->create([
            'name'        => 'ruc_emisor',
            'regex'       => 'RUC(?:\s+emisor)?:?\s*(\d{13})',
            'description' => 'RUC del emisor',
            'version'     => 1,
            'active'      => true,
        ]);

        $this->get(FieldPatternResource::getUrl('edit', ['record' => $pattern]))
            ->assertSuccessful();
    }

    public function test_un_regex_roto_se_marca_como_roto_en_el_listado(): void
    {
        $this->admin();

        FieldPattern::query()->create([
            'name'    => 'roto',
            // Paréntesis sin cerrar: compila en el worker? No. Por eso la
            // columna lo tiene que marcar en rojo, no dejarlo pasar en gris.
            'regex'   => '(no-es-regex',
            'version' => 1,
            'active'  => true,
        ]);

        $this->get(FieldPatternResource::getUrl('index'))
            ->assertSuccessful()
            ->assertSee('no compila');
    }

    public function test_formulario_de_regla_de_validacion_renderiza(): void
    {
        $this->admin();

        $rule = ValidationRule::query()->create([
            'name'       => 'factura_vigente',
            'json_rule'  => [
                'conditions' => [
                    'all' => [[
                        'fact'     => 'days_since_date',
                        'operator' => 'greaterThan',
                        'value'    => 90,
                    ]],
                ],
                'message'    => 'La factura tiene más de 90 días.',
                'code'       => 'FACTURA_VENCIDA',
            ],
            'severity'   => 'error',
            'doc_types'  => ['factura'],
            'version'    => 1,
            'active'     => true,
        ]);

        $this->get(ValidationRuleResource::getUrl('edit', ['record' => $rule]))
            ->assertSuccessful()
            // El JSON se formatea para poder leerlo en el textarea.
            ->assertSee('days_since_date');
    }

    public function test_listado_de_reglas_muestra_los_facts_referenciados(): void
    {
        $this->admin();

        ValidationRule::query()->create([
            'name'      => 'factura_vigente',
            'json_rule' => [
                'conditions' => ['all' => [[
                    'fact'     => 'days_since_date',
                    'operator' => 'greaterThan',
                    'value'    => 90,
                ]]],
                'message' => 'Muy vieja.',
                'code'    => 'VENCIDA',
            ],
            'severity'  => 'error',
            'doc_types' => ['factura'],
            'version'   => 1,
            'active'    => true,
        ]);

        $this->get(ValidationRuleResource::getUrl('index'))
            ->assertSuccessful()
            ->assertSee('days_since_date');
    }

    public function test_formulario_de_prompt_renderiza(): void
    {
        $this->admin();

        $prompt = ValidationPrompt::query()->create([
            'name'             => 'validate_factura_v1',
            'prompt_text'      => 'Revisa la factura y decide.',
            'expected_schema'  => ['approved' => 'boolean', 'issues' => 'array'],
            'version'          => 1,
            'active'           => true,
        ]);

        $this->get(ValidationPromptResource::getUrl('edit', ['record' => $prompt]))
            ->assertSuccessful()
            ->assertSee('Probar prompt');
    }

    public function test_listado_de_prompts_arranca(): void
    {
        $this->admin();

        ValidationPrompt::query()->create([
            'name'        => 'classify',
            'prompt_text' => 'Clasifica el documento.',
            'version'     => 1,
            'active'      => true,
        ]);

        $this->get(ValidationPromptResource::getUrl('index'))
            ->assertSuccessful()
            ->assertSee('classify');
    }

    public function test_los_prompts_conocidos_son_los_que_busca_el_worker(): void
    {
        // Si se agrega un doc_type al seeder sin su prompt, el worker cae al
        // prompt genérico sin avisar. Este test es la red que lo detecta.
        $this->admin();

        // `RefreshDatabase` no corre los seeders: hay que pedirlos explícitos.
        $this->seed([\Database\Seeders\DocTypeRuleSeeder::class]);
        $this->seed([\Database\Seeders\ValidationPromptSeeder::class]);

        $docTypes = DocTypeRule::query()->pluck('name');

        $this->assertNotEmpty($docTypes, 'El seeder debería traer doc_type_rules.');

        $prompts = ValidationPrompt::query()->pluck('name');

        $this->assertContains('classify', $prompts->all());

        $this->assertContains('validate_default_v1', $prompts->all());

        // Cada doc_type con extract_/validate_ tiene su prompt versionado.
        foreach (['extract_', 'validate_'] as $prefixo) {
            foreach ($docTypes as $docType) {
                if (in_array($docType, ['rfc'], true)) {
                    continue;
                }

                $this->assertContains(
                    "{$prefixo}{$docType}_v1",
                    $prompts->all(),
                    "Falta el prompt {$prefixo}{$docType}_v1 para el doc_type '{$docType}'.",
                );
            }
        }
    }

    public function test_el_formulario_conoce_exactosamente_los_prompts_del_seeder(): void
    {
        // El formulario solo ofrece nombres de una lista hardcodeada. Si el
        // seeder carga un prompt que no está ahí, no se puede editar desde el
        // admin: el select queda sin la opción y el campo no valida.
        $this->admin();

        $this->seed([\Database\Seeders\ValidationPromptSeeder::class]);

        $known = ValidationPromptForm::KNOWN_NAMES;

        $seeded = ValidationPrompt::query()->pluck('name');

        foreach ($seeded as $name) {
            $this->assertArrayHasKey(
                $name,
                $known,
                "El prompt '{$name}' existe en la BD pero no en ValidationPromptForm::KNOWN_NAMES.",
            );
        }

        foreach (array_keys($known) as $name) {
            $this->assertContains(
                $name,
                $seeded->all(),
                "ValidationPromptForm::KNOWN_NAMES ofrece '{$name}', que el seeder no crea.",
            );
        }
    }

    public function test_el_nombre_del_prompt_no_se_puede_editar(): void
    {
        // El name vincula el prompt con el tipo documental. Si se renombra,
        // el worker deja de encontrarlo en silencio.
        $this->admin();

        $prompt = ValidationPrompt::query()->create([
            'name'        => 'classify',
            'prompt_text' => 'Clasifica.',
            'version'     => 1,
            'active'      => true,
        ]);

        $this->get(ValidationPromptResource::getUrl('edit', ['record' => $prompt]))
            ->assertSuccessful()
            ->assertSee('classify', escape: false);
    }

    // ── El admin completo ──────────────────────────────────────────────────

    public function test_todas_las_paginas_abren(): void
    {
        $this->admin();

        $email = ProcessedEmail::factory()->create();
        Attachment::factory()->forEmail($email)->create();

        $docRule = DocTypeRule::query()->firstOrCreate(
            ['name' => 'factura'],
            [
                'label'             => 'Factura',
                'required_keywords' => ['factura'],
                'keywords'          => ['total'],
                'filename_hints'    => ['factura'],
                'active'            => true,
                'version'           => 1,
            ],
        );

        $pattern = FieldPattern::query()->firstOrCreate(
            ['name' => 'ruc_emisor'],
            ['regex' => 'RUC:?\s*(\d{13})', 'version' => 1, 'active' => true],
        );

        $rule = ValidationRule::query()->firstOrCreate(
            ['name' => 'factura_vigente'],
            [
                'json_rule' => ['conditions' => ['all' => [[
                    'fact' => 'days_since_date', 'operator' => 'greaterThan', 'value' => 90,
                ]]]],
                'severity'  => 'error',
                'doc_types' => ['factura'],
                'version'   => 1,
                'active'    => true,
            ],
        );

        $prompt = ValidationPrompt::query()->firstOrCreate(
            ['name' => 'classify'],
            ['prompt_text' => 'Clasifica.', 'version' => 1, 'active' => true],
        );

        $pages = [
            AttachmentResource::getUrl('index'),
            DocTypeRuleResource::getUrl('index'),
            DocTypeRuleResource::getUrl('edit', ['record' => $docRule]),
            EmailResponseResource::getUrl('index'),
            FieldPatternResource::getUrl('index'),
            FieldPatternResource::getUrl('edit', ['record' => $pattern]),
            JobResource::getUrl('index'),
            JobResource::getUrl('view', ['record' => $email]),
            LlmCallResource::getUrl('index'),
            ValidationPromptResource::getUrl('index'),
            ValidationPromptResource::getUrl('edit', ['record' => $prompt]),
            ValidationResultResource::getUrl('index'),
            ValidationRuleResource::getUrl('index'),
            ValidationRuleResource::getUrl('edit', ['record' => $rule]),
            WatchStateResource::getUrl('index'),
            GmailConnection::getUrl(),
        ];

        foreach ($pages as $url) {
            $this->get($url)->assertSuccessful();
        }
    }

    /**
     * La página de conexión cambia entera según el modo de credencial, y las dos
     * ramas leen datos distintos (`$mailboxes` del `.env` vs. el token). Con el
     * default `service_account` la otra rama no se ejecutaría nunca.
     */
    public function test_la_pagina_de_conexion_abre_en_los_dos_modos(): void
    {
        $this->admin();

        // Sin esto la página lee el token REAL del entorno de desarrollo: si
        // hay una cuenta autorizada, muestra "Cuenta autorizada" y el test
        // falla por un archivo que no es del test.
        config([
            'gmail_docs.google.auth_mode' => 'oauth',
            'gmail_docs.google.oauth.token_path' => sys_get_temp_dir().'/gdv-inexistente-'.uniqid().'.json',
        ]);
        $this->get(GmailConnection::getUrl())->assertSuccessful()->assertSee('Todavía no hay ninguna cuenta autorizada');

        config(['gmail_docs.google.auth_mode' => 'service_account']);
        $this->get(GmailConnection::getUrl())->assertSuccessful()->assertSee('Credencial de dominio');
    }
}
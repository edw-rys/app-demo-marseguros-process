<?php

namespace Tests\Feature;

use App\Filament\Resources\DocTypeRules\DocTypeRuleResource;
use App\Filament\Resources\Jobs\JobResource;
use App\Models\DocTypeRule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * El admin tiene que ABRIR, no solo registrar rutas.
 *
 * `route:list` pasa aunque una columna referencie un atributo inexistente o un
 * formulario llame a un método que ya no existe: el error sale recién cuando la
 * página se renderiza. Estos tests renderizan de verdad.
 */
class AdminPanelTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $user = User::factory()->create(['email' => 'panel@demo.local']);

        $this->actingAs($user);

        return $user;
    }

    public function test_el_dashboard_arranca(): void
    {
        $this->admin();

        $this->get('/admin')->assertSuccessful();
    }

    public function test_el_listado_de_jobs_arranca_vacio(): void
    {
        $this->admin();

        $this->get(JobResource::getUrl('index'))->assertSuccessful();
    }

    public function test_el_listado_de_jobs_arranca_con_datos(): void
    {
        $this->admin();

        \App\Models\ProcessedEmail::factory()->count(3)->create();

        $this->get(JobResource::getUrl('index'))->assertSuccessful();
    }

    public function test_el_detalle_de_un_job_renderiza_el_timeline(): void
    {
        $this->admin();

        $email = \App\Models\ProcessedEmail::factory()->create();

        app(\App\Services\Pipeline\EmailPipeline::class, ['email' => $email])->run();

        $response = $this->get(JobResource::getUrl('view', ['record' => $email]));

        $response->assertSuccessful();
        // El timeline es el entregable pedido: tiene que estar en el HTML.
        $response->assertSee('Ejecución #1');
        $response->assertSee('Etapas del an');
    }

    public function test_el_timeline_agrupa_cada_reproceso_en_su_propia_corrida(): void
    {
        $this->admin();

        $email = \App\Models\ProcessedEmail::factory()
            ->withSubject('Trámite: Factura de agosto')
            ->forStack('a')
            ->create();

        \App\Models\Attachment::factory()->forEmail($email)->classified('factura')->create();

        $pipeline = app(\App\Services\Pipeline\EmailPipeline::class, ['email' => $email]);
        $pipeline->run();
        \App\Models\AnalysisCache::query()->delete();
        app(\App\Services\Pipeline\EmailPipeline::class, ['email' => $email])->run();

        $response = $this->get(JobResource::getUrl('view', ['record' => $email->refresh()]));

        $response->assertSuccessful();
        // Las dos corridas se ven separadas, no como una sola larga.
        $response->assertSee('Ejecución #2');
        $response->assertSee('Ejecución #1');
    }

    public function test_el_listado_de_reglas_de_tipo_arranca(): void
    {
        $this->admin();

        DocTypeRule::query()->create([
            'name'              => 'factura',
            'label'             => 'Factura de venta',
            'required_keywords' => ['factura', 'ruc'],
            'keywords'          => ['total', 'iva'],
            'filename_hints'    => ['factura'],
            'active'            => true,
            'version'           => 1,
        ]);

        $this->get(DocTypeRuleResource::getUrl('index'))->assertSuccessful();
    }

    public function test_el_formulario_de_regla_de_tipo_renderiza(): void
    {
        $this->admin();

        $rule = DocTypeRule::query()->create([
            'name'              => 'factura',
            'label'             => 'Factura de venta',
            'required_keywords' => ['factura'],
            'keywords'          => ['iva'],
            'filename_hints'    => ['factura'],
            'active'            => true,
            'version'           => 1,
        ]);

        $this->get(DocTypeRuleResource::getUrl('edit', ['record' => $rule]))
            ->assertSuccessful();
    }

    public function test_el_admin_exige_autenticacion(): void
    {
        // Sin sesión debe redirigir al login, no servir el panel.
        $this->get(JobResource::getUrl('index'))->assertRedirect();
    }
}
<?php

namespace Tests\Feature;

use App\Http\Controllers\JobStreamController;
use App\Models\JobStage;
use App\Models\ProcessedEmail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El stream SSE del detalle de un job.
 *
 * Lo que se prueba acá es el CONTRATO del endpoint, que es lo que el JS consume:
 * qué eventos salen, en qué orden, y qué pasa cuando el job ya terminó. El
 * `StreamedResponse` se ejecuta con `getContent()`, que lo corre entero y
 * devuelve el cuerpo ya acumulado — suficiente para verificar el formato.
 */
class JobStreamTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Ver `streamBodyFor()`: sin acotar el stream, el test de un job en proceso
        // se quedaría esperando los 600 s del límite de producción.
        config([
            'gmail_docs.stream.poll_ms'          => 20,
            'gmail_docs.stream.keepalive_seconds' => 1,
            'gmail_docs.stream.max_seconds'       => 1,
        ]);
    }

    private function admin(): User
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'web');

        return $user;
    }

    public function test_sin_sesion_no_emite_nada(): void
    {
        $email = ProcessedEmail::factory()->create(['status' => ProcessedEmail::STATUS_PROCESSING]);

        $this->get(route('jobs.stream', ['uuid' => $email->uuid]))
            ->assertRedirect();

        // Y lo que importa: no se abrió ningún stream. Un endpoint de eventos
        // sin auth que se pueda abrir desde internet filtraría el asunto del
        // correo y los errores de cada etapa.
        $this->assertSame(0, JobStage::query()->count());
    }

    public function test_rechaza_un_uuid_inexistente(): void
    {
        $this->admin();

        $this->getJson(route('jobs.stream', ['uuid' => 'no-existe']))
            ->assertNotFound();
    }

    /** Un job ya terminado emite sus etapas y cierra. */
    public function test_un_job_terminado_reproduce_las_etapas_guardadas(): void
    {
        $this->admin();

        $email = ProcessedEmail::factory()->create([
            'status'        => ProcessedEmail::STATUS_VALIDATED,
            'current_stage' => 'done',
        ]);

        $this->stage($email, 'received', 'passed', 1);
        $this->stage($email, 'download', 'passed', 503);
        $this->stage($email, 'done', 'passed', 0);

        $body = $this->streamFor($email);

        $this->assertStringContainsString('event: estado', $body);
        $this->assertStringContainsString('event: etapa', $body);
        $this->assertStringContainsString('event: fin', $body);

        // Los labels salen del enum, no del front.
        $this->assertStringContainsString('Descarga de adjuntos', $body);

        // Y cierra con el estado final, para que el front sepa que ya no hay
        // más que escuchar.
        $this->assertStringContainsString('"status":"validated"', $body);
    }

    /**
     * El caso que rompe si el cursor no funciona: al reconectar con
     * `last_event_id`, lo ya entregado NO se repite.
     */
    public function test_el_cursor_evita_repetir_etapas(): void
    {
        $this->admin();

        $email = ProcessedEmail::factory()->create([
            'status'        => ProcessedEmail::STATUS_VALIDATED,
            'current_stage' => 'done',
        ]);

        $this->stage($email, 'received', 'passed', 1);   // sequence 1
        $this->stage($email, 'download', 'passed', 503); // sequence 2
        $this->stage($email, 'done', 'passed', 0);       // sequence 3

        $full = $this->streamFor($email);

        $this->assertStringContainsString('Descarga de adjuntos', $full);

        $despues = $this->streamFor($email, lastEventId: 2);

        $this->assertStringNotContainsString('Descarga de adjuntos', $despues);
        $this->assertStringContainsString('Finalizado', $despues);
    }

    /** La cabecera `Last-Event-ID` es el mismo cursor que el query param. */
    public function test_acepta_last_event_id_por_cabecera(): void
    {
        $this->admin();

        $email = ProcessedEmail::factory()->create([
            'status'        => ProcessedEmail::STATUS_VALIDATED,
            'current_stage' => 'done',
        ]);

        $this->stage($email, 'received', 'passed', 1);
        $this->stage($email, 'done', 'passed', 0);

        // Se pide de verdad por HTTP para que el controller resuelva el cursor
        // desde la cabecera, que es como lo manda el `EventSource` al reconectar.
        $this->withHeader('Last-Event-ID', '1')
            ->get(route('jobs.stream', ['uuid' => $email->uuid]))
            ->assertSuccessful();

        // Y el efecto del cursor se comprueba sobre el bucle con ese `last_id`.
        $body = $this->streamBodyFor($email, 1);

        $this->assertStringNotContainsString('Recibido', $body);
        $this->assertStringContainsString('Finalizado', $body);
    }

    /**
     * Un job en `processing` no termina nunca solo: el stream sigue abierto.
     *
     * Cuando se agota el tiempo máximo se emite `timeout`, NO `fin`. La
     * diferencia importa: el JS trata `fin` como "el job terminó, recargá el
     * detalle", así que un `fin` en un timeout haría recargar la página de un
     * job que en realidad sigue corriendo.
     */
    public function test_un_job_en_proceso_emite_timeout_y_no_fin(): void
    {
        $this->admin();

        $email = ProcessedEmail::factory()->create([
            'status'        => ProcessedEmail::STATUS_PROCESSING,
            'current_stage' => 'classify',
        ]);

        $this->stage($email, 'received', 'passed', 1);

        $body = $this->streamFor($email);

        $this->assertStringContainsString('event: etapa', $body);
        $this->assertStringContainsString('event: timeout', $body);
        $this->assertStringNotContainsString('event: fin', $body);
    }

    /**
     * El estado no se reenvía si no cambió.
     *
     * El bucle corre cada 400 ms; un job en cola puede no producir ninguna
     * etapa durante minutos. Mandar el mismo `estado` 150 veces por minuto es
     * ancho de banda y parseos en el navegador tirados a la basura.
     */
    public function test_el_estado_no_se_reenvia_si_no_cambio(): void
    {
        $this->admin();

        $email = ProcessedEmail::factory()->create([
            'status'        => ProcessedEmail::STATUS_PROCESSING,
            'current_stage' => 'classify',
        ]);

        $body = $this->streamFor($email);

        // Con `poll_ms=20` y `max_seconds=1` el bucle da ~50 vueltas. Solo la
        // primera debe emitir estado; el resto no cambió nada.
        $this->assertSame(
            1,
            substr_count($body, 'event: estado'),
            'El estado se reenvió aunque no hubiera cambiado.',
        );
    }

    /** El error de una etapa viaja al front: si no, el panel muestra "OK". */
    public function test_el_error_de_una_etapa_llega_en_el_evento(): void
    {
        $this->admin();

        $email = ProcessedEmail::factory()->create([
            'status'        => ProcessedEmail::STATUS_FAILED,
            'current_stage' => 'download',
        ]);

        JobStage::query()->create([
            'email_id'    => $email->id,
            'run'         => 1,
            'stack'       => 'b',
            'stage_key'   => 'download',
            'sequence'    => 2,
            'status'      => 'failed',
            'severity'    => 'error',
            'error'       => 'Invalid attachment token',
            'started_at'  => now(),
            'finished_at' => now(),
            'duration_ms' => 2120,
        ]);

        $body = $this->streamFor($email);

        $this->assertStringContainsString('Invalid attachment token', $body);
        $this->assertStringContainsString('"duration_ms":2120', $body);
    }

    /** Las cabeceras que hacen que esto sea un stream y no una respuesta normal. */
    public function test_manda_las_cabeceras_de_sse(): void
    {
        $this->admin();

        $email = ProcessedEmail::factory()->create([
            'status'        => ProcessedEmail::STATUS_VALIDATED,
            'current_stage' => 'done',
        ]);

        $response = $this->get(route('jobs.stream', ['uuid' => $email->uuid]));

        // Symfony le pega `; charset=utf-8` al Content-Type. El `EventSource` del
        // navegador no le importa, así que se compara el prefijo en vez de
        // exigir la cadena exacta.
        $this->assertStringStartsWith('text/event-stream', $response->headers->get('Content-Type'));
        $response->assertHeader('X-Accel-Buffering', 'no');

        $cache = $response->baseResponse->headers->get('Cache-Control');

        $this->assertStringContainsString('no-cache', $cache);
    }

    // ── Utilidades ─────────────────────────────────────────────────────────

    private function stage(ProcessedEmail $email, string $key, string $status, int $ms): JobStage
    {
        return JobStage::query()->create([
            'email_id'    => $email->id,
            'run'         => 1,
            'stack'       => 'b',
            'stage_key'   => $key,
            'sequence'    => (int) JobStage::query()->where('email_id', $email->id)->max('sequence') + 1,
            'status'      => $status,
            'started_at'  => now(),
            'finished_at' => now(),
            'duration_ms' => $ms,
        ]);
    }

    private function streamFor(ProcessedEmail $email, ?int $lastEventId = null): string
    {
        return $this->streamBodyFor($email, $lastEventId ?? 0);
    }

    /**
     * Corre el bucle del stream sobre una instancia que acumula en memoria.
     *
     * No se captura con `ob_start()` a propósito: el stream tiene que cerrar
     * los buffers de salida para que los eventos lleguen al navegador, y eso
     * desencola también el buffer del test — el `echo` se iría a la salida real
     * y `ob_get_clean()` devolvería vacío. Por eso el controller tiene el seam
     * `write()` y acá se corre `pump()` sobre una subclase que guarda los
     * chunks en un array.
     *
     * `setUp()` deja `stream.max_seconds` en 1: un job en `processing` nunca
     * emite `fin`, así que sin ese tope el test colgaría los 600s de
     * producción.
     */
    private function streamBodyFor(ProcessedEmail $email, int $lastEventId = 0): string
    {
        $spy = new class extends JobStreamController
        {
            /** @var array<int, string> */
            public array $chunks = [];

            protected function write(string $chunk): void
            {
                $this->chunks[] = $chunk;
            }
        };

        // El bucle escribe directo al array; el resto de PHP sigue buffereando
        // normalmente, así que no hay que tocar los buffers reales.
        $spy->pump($email, $lastEventId);

        return implode('', $spy->chunks);
    }
}
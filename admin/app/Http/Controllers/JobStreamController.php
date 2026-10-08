<?php

namespace App\Http\Controllers;

use App\Models\ProcessedEmail;
use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * SSE del pipeline: `/jobs/{uuid}/stream`.
 *
 * Por qué consultar la BD y no un bus de eventos: el pipeline corre en OTRO
 * proceso. `supervisord.conf` lanza `queue:work --queue=pipeline` aparte de
 * `php-fpm`, así que el `StageRecorder` que escribe cada etapa vive en el worker
 * y este controller vive en el worker de web: no comparten memoria, y un
 * broadcaster en memoria no vería nada.
 *
 * Y por qué la BD y no Redis/Reverb: no hay ninguno en el stack
 * (`CACHE_STORE=database`, `BROADCAST_CONNECTION=log`), y `job_stages` ya es
 * exactamente el log que se quiere transmitir — append-only, con `sequence`
 * monotónico, que da un cursor gratis. El SSE no necesita "recibir" eventos,
 * solo necesita notar que el `sequence` del último que vio cambió.
 *
 * El costo es un SELECT por conexión cada `poll_ms`. Es barato a la escala de
 * esta demo (decenas de jobs) y evita agregar una pieza de infraestructura solo
 * para empujar filas.
 */
class JobStreamController extends Controller
{
    /**
     * Cuánto se duerme entre consultas.
     *
     * 400 ms es el punto donde la demora se vuelve invisible para un ojo humano
     * sin castigar la BD. Menos reactivo que un push real, más simple y sin
     * dependencias. Configurable para que los tests no tarden.
     */
    private function pollMs(): int
    {
        return max(1, (int) config('gmail_docs.stream.poll_ms', 400));
    }

    /**
     * Cada cuánto se manda un comentario keep-alive.
     *
     * Sin esto, un stream que no recibe etapas (un job en cola que aún no
     * arrancó, o una etapa larga de OCR) queda sin escribir nada. nginx
     * (`fastcgi_read_timeout 1h`) aguantaría, pero cualquier proxy intermedio
     * del despliegue puede cortar la conexión por inactividad, y el `EventSource`
     * del navegador reconecta SOLO si el servidor cerró limpio.
     */
    private function keepaliveSeconds(): int
    {
        return max(1, (int) config('gmail_docs.stream.keepalive_seconds', 15));
    }

    /**
     * Corta el stream a los 10 minutos aunque el job siga vivo.
     *
     * Es la red de seguridad, no la regla: un job de verdad termina en segundos
     * o menos de 10 minutos. Lo que evita es que una fuga (un `EventSource` que
     * el JS no cerró) retenga un worker de php-fpm indefinidamente. El browser
     * reconecta solo y el replay le devuelve todo lo que se perdió.
     */
    private function maxStreamSeconds(): int
    {
        return max(1, (int) config('gmail_docs.stream.max_seconds', 600));
    }

    /**
     * Máximo de streams simultáneos, en toda la app.
     *
     * Cada conexión retiene un worker de php-fpm mientras dure (ver
     * `docker/php-fpm-pool.conf`). Con el pool en 30 el margen es amplio, pero
     * un `while(true)` de streams abiertos —una pestaña de más abierta por
     * error, un crawler— se comería el panel entero.
     */
    private function maxConcurrentStreams(): int
    {
        return max(1, (int) config('gmail_docs.stream.max_concurrent', 8));
    }

    /** Streams vivos, solo en esta instancia. Ver `acquireSlot()`. */
    private static int $activeStreams = 0;

    public function __invoke(Request $request, string $uuid): StreamedResponse
    {
        $email = ProcessedEmail::query()
            ->where('uuid', $uuid)
            ->firstOrFail();

        // Autorización: el SSE lleva datos del job, así que no puede quedar
        // fuera del control de acceso del panel. Reusa el middleware de
        // Filament — es el único que este repo tiene; el alias `auth` de
        // Laravel redirige a `route('login')`, que no existe (el login vive en
        // `filament.admin.auth.login`) y devolvería un 500.
        if (! Filament::auth()->check()) {
            abort(401, 'Sesión no válida.');
        }

        return $this->stream($email, $request);
    }

    private function stream(ProcessedEmail $email, Request $request): StreamedResponse
    {
        // `Last-Event-ID`: si el browser reconecta, el `EventSource` manda en
        // la cabecera el id del último evento que recibió. Es lo que evita que al
        // reconectar se repita todo el timeline desde cero.
        $lastId = (int) ($request->headers->get('Last-Event-ID') ?? $request->query('last_event_id', 0));

        $response = new StreamedResponse;
        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache, no-store');
        $response->headers->set('Connection', 'keep-alive');
        // nginx además lo infiere de su config, pero mandarlo también ayuda
        // si el stream termina detrás de un proxy en el despliegue real.
        $response->headers->set('X-Accel-Buffering', 'no');

        // Sin esto PHP acumula el cuerpo en el buffer de salida y nada sale
        // hasta el final: el stream entero llegaría de golpe, ya sin ser stream.
        $response->setCallback(function () use ($email, $lastId): void {
            $this->withoutOutputBuffering();

            // `connection_aborted()` solo se actualiza si PHP puede escribir;
            // por eso el heartbeat también sirve como detector de cierre.
            if (! $this->acquireSlot()) {
                $this->send('aviso', $this->event('aviso', [
                    'message' => 'Demasiadas conexiones en vivo abiertas a la vez. '
                        .'Se actualizará solo cuando se liberen.',
                ]));

                return;
            }

            try {
                $this->pump($email, $lastId);
            } finally {
                self::$activeStreams--;
            }
        });

        return $response;
    }

    /**
     * El bucle: consulta, emite lo nuevo, duerme.
     *
     * @param  int  $lastId  `sequence` del último evento ya entregado.
     */
    /**
     * El bucle que consulta y emite. `public` para que el test pueda correrlo
     * sobre una subclase con `write()` interceptado (ver `JobStreamTest`).
     */
    public function pump(ProcessedEmail $email, int $lastId): void
    {
        $deadline = microtime(true) + $this->maxStreamSeconds();
        $lastKeepalive = microtime(true);

        // Estado inicial del job, para que el front sepa si esperar o no.
        //
        // Se recuerda en `$lastState` desde el vamos: si arrancara en `null`,
        // la primera vuelta del bucle compararía contra "nada" y reenviaría un
        // estado idéntico al que se acaba de mandar.
        $lastState = $this->jobState($email);

        $this->send('estado', $lastState);

        while (microtime(true) < $deadline) {
            if (connection_aborted() !== 0) {
                return;
            }

            $stages = $this->stagesAfter($email, $lastId);

            foreach ($stages as $stage) {
                $lastId = max($lastId, (int) $stage->sequence);

                $this->send('etapa', $this->stageEvent($email, $stage));
            }

            // Se relee el email porque el worker está escribiendo en la misma
            // tabla: sin esto el front no se enteraría de que el job terminó
            // hasta que llegara la etapa `done`, que en un job fallido nunca
            // llega.
            $email->refresh();

            $state = $this->jobState($email);

            // Solo se reenvía si algo cambió de verdad. Sin esta comparación, el
            // bucle de 400 ms mandaría 2.5 eventos por segundo con el mismo
            // contenido en un job en cola —donde no se registra ninguna etapa
            // durante minutos. El front los descartaría igual; lo que se evita
            // es gastar ancho de banda y parseos en el navegador.
            if ($state !== $lastState) {
                $this->send('estado', $state);
                $lastState = $state;
            }

            if ($email->isFinished()) {
                $this->send('fin', $this->event('fin', [
                    'status'     => $email->status,
                    'stage'      => $email->current_stage,
                    'last_event' => $lastId,
                ]));

                return;
            }

            if (microtime(true) - $lastKeepalive >= $this->keepaliveSeconds()) {
                $lastKeepalive = microtime(true);
                // Un comentario `:` no es un evento: no llega al `EventSource`,
                // solo mantiene viva la conexión.
                $this->write(': keep-alive '."\n\n");
            }

            usleep($this->pollMs() * 1000);
        }

        // Se agotó el tiempo máximo. Se emite `timeout` y NO `fin` a propósito:
        // `fin` significa "el job terminó, recargá el detalle", y confundirse
        // con un corte de red haría recargar la página en falso. El JS reconecta
        // solo y el replay parte de `last_event_id`, así que no pierde nada.
        $this->send('timeout', $this->event('timeout', [
            'status'     => $email->fresh()?->status,
            'stage'      => $email->fresh()?->current_stage,
            'last_event' => $lastId,
        ]));
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\JobStage> */
    private function stagesAfter(ProcessedEmail $email, int $lastId)
    {
        return $email->stages()
            ->where('sequence', '>', $lastId)
            ->orderBy('sequence')
            ->get();
    }

    /**
     * Estado del job, en la misma forma que devuelve el listado.
     *
     * @return array<string, mixed>
     */
    private function jobState(ProcessedEmail $email): array
    {
        return [
            'uuid'          => $email->uuid,
            'status'        => $email->status,
            'current_stage' => $email->current_stage,
            'subject'       => $email->subject,
            'finished'      => $email->isFinished(),
            'stack'         => $email->pipeline_stack,
        ];
    }

    /**
     * Una etapa, con lo que el timeline necesita para pintar la fila.
     *
     * Los labels y colores salen del enum, NO del front: si mañana se agrega una
     * etapa, el front la muestra bien sin tocar JavaScript. Ese era el punto de
     * tenerlos en `PipelineStage::label()`.
     *
     * @return array<string, mixed>
     */
    private function stageEvent(ProcessedEmail $email, $stage): array
    {
        $enum = $stage->stageEnum();

        return [
            'sequence'      => (int) $stage->sequence,
            'run'           => (int) $stage->run,
            'stage'         => $stage->stage_key,
            'label'         => $enum?->label() ?? $stage->stage_key,
            'description'   => $enum?->description(),
            'status'        => $stage->status,
            'duration_ms'   => $stage->duration_ms,
            'severity'      => $stage->severity,
            'message'       => $stage->message,
            'error'         => $stage->error,
            'attachment_id' => $stage->attachment_id,
            'filename'      => $stage->attachment?->filename,
            'started_at'    => $stage->started_at?->toIso8601String(),
            'finished_at'   => $stage->finished_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function event(string $name, array $payload): array
    {
        return ['event' => $name] + $payload;
    }

    /**
     * Escribe un evento SSE.
     *
     * El `id:` es lo que permite `Last-Event-ID` al reconectar. Usa el
     * `sequence` de la etapa, que es monotónico por diseño (`StageRecorder`
     * lo incrementa siempre), así que sirve como cursor.
     */
    private function send(string $name, array $payload): void
    {
        $id = $payload['sequence'] ?? null;

        $out = $id !== null ? "id: {$id}\n" : '';

        $out .= "event: {$name}\n";
        $out .= 'data: '.json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n\n";

        $this->write($out);
    }

    /**
     * Escribe un chunk al cliente.
     *
     * El `echo` va en un método aparte a propósito. El stream necesita cerrar
     * los buffers de salida —si no, nada sale hasta el final— y eso hace
     * IMPOSIBLE capturar la salida con `ob_start()` desde el test: el propio
     * controller se desencola el buffer. Con este seam, el test inyecta una
     * subclase que acumula en un array en vez de escribir, y puede leer el
     * stream completo sin abrir un socket.
     *
     * `protected`, no `private`: es lo que el test extiende.
     */
    protected function write(string $chunk): void
    {
        echo $chunk;

        if (ob_get_level() > 0) {
            @ob_flush();
        }

        flush();
    }

    private function acquireSlot(): bool
    {
        if (self::$activeStreams >= $this->maxConcurrentStreams()) {
            return false;
        }

        self::$activeStreams++;

        return true;
    }

    /**
     * Desactiva el buffer de salida y el buffer implícito de PHP.
     *
     * Los dos hacen falta: `output_buffering` es el que PHP aplica al script
     * entero, y `implicit_flush` sin él es un no-op. Sin esto nada sale hasta
     * que termina la respuesta, que es exactamente lo contrario de un stream.
     */
    private function withoutOutputBuffering(): void
    {
        while (ob_get_level() > 0) {
            ob_end_flush();
        }

        // Los `ini_set` van AQUÍ, dentro del callback, y no antes de armar la
        // respuesta: cualquier `ini_set` que toque compression o buffering lanza
        // un warning "headers already sent" en cuanto se manda el primer byte,
        // y con `display_errors=Off` eso se convierte en un error silencioso que
        // además rompe el stream a mitad.
        ini_set('output_buffering', '0');
        ini_set('implicit_flush', '1');

        // `zlib` solo: si no hay output compression activo, `ini_set` es inocuo.
        // Con `gzip on` en nginx (ver el bloque del stream, que lo apaga) sería
        // un no-op; se deja como red de seguridad para despliegues que tengan
        // compression activada en otro punto de la cadena.
        if (ini_get('zlib.output_compression')) {
            ini_set('zlib.output_compression', '0');
        }

        // El job puede tardar minutos y el stream está vivo todo ese tiempo.
        set_time_limit(0);
    }
}
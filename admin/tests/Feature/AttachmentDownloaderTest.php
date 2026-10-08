<?php

namespace Tests\Feature;

use App\Models\ProcessedEmail;
use App\Services\Attachments\AttachmentDownloader;
use App\Services\Gmail\GmailClient;
use App\Support\StoragePath;
use Google\Service\Gmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * La descarga de adjuntos contra la Gmail API.
 *
 * El test que faltaba y por eso el bug llegó a runtime: `downloadFromGmail()`
 * es la única parte del sistema que habla con `users.messages.attachments.get`,
 * y la firma real de ese método tiene el `attachmentId` como tercer argumento
 * POSICIONAL. Pasarlo dentro de un array de opciones hace que Google conteste
 * 400 "Invalid attachment token" — que es exactamente lo que pasó con el correo
 * "Trámite: Reclamo Fmlia Ampuero".
 */
class AttachmentDownloaderTest extends TestCase
{
    use RefreshDatabase;

    private string $attachmentsRoot;

    protected function setUp(): void
    {
        parent::setUp();

        // Un directorio temporal por test: `downloadFor()` escribe archivos de
        // verdad, y con la raíz real un fallo dejaría basura en el volumen
        // compartido que los workers Python después leerían.
        $this->attachmentsRoot = sys_get_temp_dir().'/gdv-adjuntos-'.uniqid();

        mkdir($this->attachmentsRoot, 0755, true);

        config(['gmail_docs.attachments.root' => $this->attachmentsRoot]);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->attachmentsRoot);

        parent::tearDown();
    }

    public function test_descarga_un_adjunto_y_lo_persiste(): void
    {
        $email = ProcessedEmail::factory()->create();
        $bytes = "%PDF-1.4\ncontenido de prueba\n%%EOF\n";

        $this->fakeGmail($email, [
            ['partId' => '1.2', 'filename' => 'factura.pdf', 'attachmentId' => 'ATTACH-1', 'data' => $bytes],
        ]);

        $result = app(AttachmentDownloader::class)->downloadFor($email);

        $this->assertSame(['factura.pdf'], $result['saved']);
        $this->assertSame([], $result['warnings']);

        $attachment = $email->attachments()->sole();

        $this->assertSame('factura.pdf', $attachment->filename);
        $this->assertSame(hash('sha256', $bytes), $attachment->sha256);
        $this->assertSame(strlen($bytes), $attachment->size_bytes);
    }

    /**
     * El test que realmente fija el bug.
     *
     * La API se llama con el `attachmentId` como tercer argumento posicional. Un
     * mock con esa misma forma hace que el código que lo pase dentro de un array
     * falle con "Invalid attachment token", que es la forma exacta en que se
     * rompió.
     */
    public function test_pasa_el_attachment_id_como_argumento_posicional(): void
    {
        $email = ProcessedEmail::factory()->create();

        $received = null;

        $this->fakeGmail($email, [
            ['partId' => '1.2', 'filename' => 'reclamo.pdf', 'attachmentId' => 'TOKEN-XYZ', 'data' => 'x'],
        ], function (array $args) use (&$received): void {
            $received = $args;
        });

        app(AttachmentDownloader::class)->downloadFor($email);

        $this->assertSame(
            ['me', $email->gmail_id, 'TOKEN-XYZ'],
            $received,
            'El attachmentId debe ir como tercer argumento posicional, no dentro de un array de opciones.',
        );
    }

    /** Un adjunto sin `attachmentId` es el cuerpo del correo: se ignora. */
    public function test_ignora_las_partes_que_no_son_adjuntos(): void
    {
        $email = ProcessedEmail::factory()->create();

        $this->fakeGmail($email, [
            // Cuerpo del correo: sin filename, sin attachmentId.
            ['partId' => '', 'filename' => null, 'attachmentId' => null, 'data' => null],
            // Con nombre pero sin attachmentId: un `text/plain` en línea.
            ['partId' => '1.1', 'filename' => null, 'attachmentId' => null, 'data' => null],
            ['partId' => '1.2', 'filename' => 'evidencia.pdf', 'attachmentId' => 'A-1', 'data' => '%PDF'],
        ]);

        $result = app(AttachmentDownloader::class)->downloadFor($email);

        $this->assertSame(['evidencia.pdf'], $result['saved']);
        $this->assertSame(1, $email->attachments()->count());
    }

    /**
     * Gmail anida los adjuntos: un PDF con portada llega dentro de un
     * `multipart/mixed`. El recorrido tiene que bajar hasta el fondo.
     */
    public function test_encuentra_adjuntos_anidados(): void
    {
        $email = ProcessedEmail::factory()->create();

        $this->fakeGmailWithNesting($email, [
            ['partId' => '2', 'filename' => 'anidado.pdf', 'attachmentId' => 'A-NESTED', 'data' => '%PDF anidado'],
        ]);

        $result = app(AttachmentDownloader::class)->downloadFor($email);

        $this->assertSame(['anidado.pdf'], $result['saved']);
    }

    /** Dos adjuntos con el mismo nombre no se pisan: el segundo lleva `_1`. */
    public function test_desempata_nombres_repetidos(): void
    {
        $email = ProcessedEmail::factory()->create();

        $this->fakeGmail($email, [
            ['partId' => '1.1', 'filename' => 'doc.pdf', 'attachmentId' => 'A-1', 'data' => 'primero'],
            ['partId' => '1.2', 'filename' => 'doc.pdf', 'attachmentId' => 'A-2', 'data' => 'segundo'],
        ]);

        $result = app(AttachmentDownloader::class)->downloadFor($email);

        $this->assertSame(['doc.pdf', 'doc_1.pdf'], $result['saved']);

        // Y lo importante: el segundo no sobreescribe al primero en disco.
        $directory = app(AttachmentDownloader::class)->directoryFor($email);

        $this->assertSame('primero', file_get_contents($directory.'/doc.pdf'));
        $this->assertSame('segundo', file_get_contents($directory.'/doc_1.pdf'));
    }

    /** RF-02: > 25 MB avisa pero NO impide la descarga. */
    public function test_un_adjunto_grande_avisa_pero_se_guarda(): void
    {
        $email = ProcessedEmail::factory()->create();

        config(['gmail_docs.attachments.size_warning_bytes' => 10]);

        $this->fakeGmail($email, [
            ['partId' => '1.1', 'filename' => 'grande.pdf', 'attachmentId' => 'A-1', 'data' => '0123456789ABCDEF'],
        ]);

        $result = app(AttachmentDownloader::class)->downloadFor($email);

        $this->assertSame(['grande.pdf'], $result['saved']);
        $this->assertCount(1, $result['warnings']);
        $this->assertStringContainsString('25 MB', $result['warnings'][0]);
    }

    public function test_escribe_el_metadata_json_del_correo(): void
    {
        $email = ProcessedEmail::factory()->create();

        $this->fakeGmail($email, [
            ['partId' => '1.1', 'filename' => 'a.pdf', 'attachmentId' => 'A-1', 'data' => '%PDF'],
        ]);

        app(AttachmentDownloader::class)->downloadFor($email);

        $metadata = json_decode(
            file_get_contents(app(AttachmentDownloader::class)->directoryFor($email).'/metadata.json'),
            true,
        );

        $this->assertSame($email->gmail_id, $metadata['gmail_id']);
        $this->assertSame(['a.pdf'], $metadata['attachments']);
        $this->assertSame(1, $metadata['attachment_count']);
    }

    public function test_sanitiza_el_nombre_del_archivo(): void
    {
        $email = ProcessedEmail::factory()->create();

        $this->fakeGmail($email, [
            ['partId' => '1.1', 'filename' => '../../etc/passwd', 'attachmentId' => 'A-1', 'data' => 'x'],
        ]);

        $result = app(AttachmentDownloader::class)->downloadFor($email);

        $this->assertSame(['passwd'], $result['saved']);
        $this->assertFileDoesNotExist('/etc/passwd-test');
    }

    // ── Dobles de prueba ──────────────────────────────────────────────────

    /**
     * Reemplaza `GmailClient` por un doble que devuelve adjuntos fijos.
     *
     * @param  array<int, array{partId: string, filename: ?string, attachmentId: ?string, data: ?string}>  $parts
     * @param  null|callable(array): void  $onAttachmentFetch  Recibe los argumentos de cada llamada.
     */
    private function fakeGmail(ProcessedEmail $email, array $parts, ?callable $onAttachmentFetch = null): void
    {
        $this->swapGmailClient(
            $this->messagePayload($parts),
            // `andReturnUsing` de Mockery pasa los argumentos RECIBIDOS como
            // parámetros sueltos, no como un array. El closure los re-empaca
            // para poder tratar las dos formas igual.
            function (...$args) use ($parts, $onAttachmentFetch) {
                if ($onAttachmentFetch) {
                    $onAttachmentFetch($args);
                }

                return $this->attachmentBodyFor($args, $parts);
            },
        );
    }

    /** Igual que `fakeGmail()`, pero dentro de un `multipart/mixed`. */
    private function fakeGmailWithNesting(ProcessedEmail $email, array $parts): void
    {
        $this->swapGmailClient(
            $this->messagePayload($parts, nested: true),
            fn (...$args): object => $this->attachmentBodyFor($args, $parts),
        );
    }

    /**
     * El token que llega tiene que corresponder a la parte que se pidió; si no
     * es el esperado, el doble devuelve un `attachmentId` que no existe, que es
     * lo que hace Google.
     */
    private function attachmentBodyFor(array $args, array $parts): object
    {
        $token = $args[2] ?? null;

        foreach ($parts as $part) {
            if ($part['attachmentId'] === $token) {
                return $this->attachmentBody($part['data'] ?? '');
            }
        }

        throw new \RuntimeException("Invalid attachment token: {$token}");
    }

    private function swapGmailClient(object $messagePayload, callable $onAttachmentsGet): void
    {
        $message = new \Google\Service\Gmail\Message;
        $message->setPayload($messagePayload);

        $messages = Mockery::mock();
        $messages->shouldReceive('get')->andReturn($message);

        $attachments = Mockery::mock();
        $attachments->shouldReceive('get')->andReturnUsing($onAttachmentsGet);

        $gmail = Mockery::mock(Gmail::class)->makePartial();
        // `users_messages` y `users_messages_attachments` son PROPIEDADES planas
        // del servicio, no métodos encadenados (el constructor de `Gmail` las
        // instancia todas).
        $gmail->users_messages = $messages;
        $gmail->users_messages_attachments = $attachments;

        $client = Mockery::mock(GmailClient::class);
        $client->shouldReceive('serviceFor')->andReturn($gmail);

        $this->app->instance(GmailClient::class, $client);
    }

    /**
     * Construye el `MessagePart` de la respuesta de Gmail.
     *
     * @param  array<int, array{partId: string, filename: ?string, attachmentId: ?string, data: ?string}>  $parts
     */
    private function messagePayload(array $parts, bool $nested = false): object
    {
        $children = [];

        foreach ($parts as $part) {
            $body = new \Google\Service\Gmail\MessagePartBody;

            if ($part['attachmentId'] !== null) {
                $body->setAttachmentId($part['attachmentId']);
                $body->setSize(10);
            }

            $child = new \Google\Service\Gmail\MessagePart;
            $child->setPartId($part['partId']);
            $child->setMimeType('application/pdf');
            $child->setFilename($part['filename']);
            $child->setBody($body);

            $children[] = $child;
        }

        if ($nested) {
            // El `multipart/mixed` que envuelve a todo.
            $inner = new \Google\Service\Gmail\MessagePart;
            $inner->setPartId('2');
            $inner->setMimeType('multipart/mixed');
            $inner->setParts($children);

            $root = new \Google\Service\Gmail\MessagePart;
            $root->setMimeType('text/plain');
            $root->setParts([$inner]);

            return $root;
        }

        $root = new \Google\Service\Gmail\MessagePart;
        $root->setMimeType('multipart/mixed');
        $root->setParts($children);

        return $root;
    }

    /** `MessagePartBody` con el `data` en base64url, como lo manda Gmail. */
    private function attachmentBody(string $data): object
    {
        $body = new \Google\Service\Gmail\MessagePartBody;
        $body->setData(rtrim(strtr(base64_encode($data), '+/', '-_'), '='));

        return $body;
    }

    private function deleteDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory.'/'.$entry;

            is_dir($path) ? $this->deleteDirectory($path) : @unlink($path);
        }

        @rmdir($directory);
    }
}
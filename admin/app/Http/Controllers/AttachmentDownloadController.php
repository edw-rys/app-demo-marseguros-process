<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use App\Support\StoragePath;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Descarga de un adjunto del job.
 *
 * Los adjuntos NO se sirven por `public/storage`: son documentos de seguro que
 * tiba un remitente externo, y `storage/app/attachments` no es público a
 * propósito. Por eso hace falta esta ruta en vez de un `<a href>` al disco.
 *
 * Pide sesión de panel (el middleware va en la ruta), no más: la demo no tiene
 * permisos por usuario todavía, así que cualquier operador del panel puede ver
 * los adjuntos de cualquier job — igual que ya podía desde la ficha del job.
 */
class AttachmentDownloadController extends Controller
{
    public function __invoke(Request $request, string $uuid): Response
    {
        $attachment = Attachment::query()->where('uuid', $uuid)->first();

        if ($attachment === null || ! $attachment->fileExists()) {
            return response()->redirectToRoute('filament.admin.resources.jobs.view', ['record' => $attachment?->email_id ?? 1]);
        }

        $path = $attachment->local_path;

        // `local_path` viene de la base, no del request, pero el control vale:
        // si alguna vez pasara a armarse con entrada del usuario, un `..` acá
        // serviría `/etc/passwd`. Se compara ya normalizado, porque `realpath`
        // resuelve los `..` y los symlinks del camino.
        //
        // La raíz sale de `StoragePath::attachments()` y NO de
        // `storage_path('app/attachments')`: en Docker los adjuntos viven en un
        // volumen montado en `/var/www/gmail-docs/attachments`, así que la ruta
        // fija no existe y TODO download daba 404.
        $root = realpath(StoragePath::attachments());
        $real = realpath($path);

        if ($root === false || $real === false || ! str_starts_with($real, $root.DIRECTORY_SEPARATOR)) {
            throw new NotFoundHttpException('El adjunto no está disponible.');
        }

        // El nombre original es solo texto de la cabecera: si trae comillas o
        // saltos de línea se rompe el header.
        $name = str_replace(["\r", "\n", '"', '/', '\\'], '', $attachment->filename);

        return response()->download($real, $name === '' ? 'adjunto' : $name, [
            'Content-Type' => $attachment->detected_kind ?: 'application/octet-stream',
        ]);
    }
}
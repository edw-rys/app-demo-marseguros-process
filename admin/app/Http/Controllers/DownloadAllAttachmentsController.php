<?php

namespace App\Http\Controllers;

use App\Models\ProcessedEmail;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use ZipArchive;

/**
 * Descarga todos los adjuntos de un job en un archivo ZIP.
 */
class DownloadAllAttachmentsController extends Controller
{
    public function __invoke(Request $request, string $uuid): Response
    {
        $email = ProcessedEmail::query()->where('uuid', $uuid)->first();

        if ($email === null) {
            throw new NotFoundHttpException('El correo / job no fue encontrado.');
        }

        $attachments = $email->attachments()->get();

        $availableFiles = [];
        $root = realpath(storage_path('app/attachments'));

        foreach ($attachments as $att) {
            if (! $att->fileExists()) {
                continue;
            }

            $real = realpath($att->local_path);
            if ($root !== false && $real !== false && str_starts_with($real, $root.DIRECTORY_SEPARATOR)) {
                $availableFiles[] = [
                    'path'     => $real,
                    'filename' => str_replace(["\r", "\n", '"', '/', '\\'], '', $att->filename),
                ];
            }
        }

        if (empty($availableFiles)) {
            return response()->redirectToRoute('filament.admin.resources.jobs.view', ['record' => $email]);
        }

        $zipFilename = 'adjuntos-job-' . Str::slug($email->subject ?: $email->uuid) . '.zip';
        $tempZip = tempnam(sys_get_temp_dir(), 'gdv_zip_');
        $zip = new ZipArchive();

        if ($zip->open($tempZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
            $usedNames = [];

            foreach ($availableFiles as $f) {
                $entryName = $f['filename'] ?: 'archivo';

                // Evitar nombres duplicados en el zip
                if (isset($usedNames[$entryName])) {
                    $usedNames[$entryName]++;
                    $pi = pathinfo($entryName);
                    $entryName = ($pi['filename'] ?? 'archivo') . '_' . $usedNames[$entryName] . (isset($pi['extension']) ? '.' . $pi['extension'] : '');
                } else {
                    $usedNames[$entryName] = 1;
                }

                $zip->addFile($f['path'], $entryName);
            }

            $zip->close();

            return response()->download($tempZip, $zipFilename, [
                'Content-Type' => 'application/zip',
            ])->deleteFileAfterSend(true);
        }

        return response()->redirectToRoute('filament.admin.resources.jobs.view', ['record' => $email]);
    }
}

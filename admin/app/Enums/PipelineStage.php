<?php

namespace App\Enums;

/**
 * Las etapas del pipeline, en orden de ejecución.
 *
 * El orden de los casos importa: `EmailPipeline` las recorre de arriba abajo y
 * el admin las muestra en el mismo orden, así que el timeline que ve el usuario
 * refleja el flujo real.
 */
enum PipelineStage: string
{
    case Received       = 'received';
    case SubjectFilter  = 'subject_filter';
    case Download       = 'download';
    case DetectKind     = 'detect_kind';
    case ExtractText    = 'extract_text';
    case Classify       = 'classify';
    case ExtractFields  = 'extract_fields';
    case Phase1Count    = 'phase1_count';
    case Phase2Validate = 'phase2_validate';
    case Reply          = 'reply';
    case Done           = 'done';

    public function label(): string
    {
        return match ($this) {
            self::Received       => 'Recibido',
            self::SubjectFilter  => 'Filtro de asunto',
            self::Download       => 'Descarga de adjuntos',
            self::DetectKind     => 'Detección de tipo',
            self::ExtractText    => 'Extracción de texto',
            self::Classify       => 'Clasificación',
            self::ExtractFields  => 'Extracción de campos',
            self::Phase1Count    => 'Fase 1 · Conteo',
            self::Phase2Validate => 'Fase 2 · Validación',
            self::Reply          => 'Respuesta',
            self::Done           => 'Finalizado',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Received       => 'El watcher detectó el correo en el buzón.',
            self::SubjectFilter  => 'Se comprobó si el asunto coincide con el filtro configurado.',
            self::Download       => 'Se descargaron los adjuntos al filesystem indexado.',
            self::DetectKind     => 'Se identificó el tipo real de cada archivo por magic bytes.',
            self::ExtractText    => 'Se obtuvo el texto (capa embebida u OCR).',
            self::Classify       => 'Se determinó la categoría documental.',
            self::ExtractFields  => 'Se extrajeron los campos clave.',
            self::Phase1Count    => 'Se verificó que estén todos los documentos requeridos.',
            self::Phase2Validate => 'Se aplicaron las reglas / prompts de validación.',
            self::Reply          => 'Se redactó la respuesta al remitente.',
            self::Done           => 'El job terminó.',
        };
    }

    /** Etapas que corren una vez por adjunto, no una vez por correo. */
    public function isPerAttachment(): bool
    {
        return in_array($this, [
            self::DetectKind,
            self::ExtractText,
            self::Classify,
            self::ExtractFields,
        ], true);
    }
}
<?php

namespace App\Filament\Resources\Attachments\Schemas;

use App\Filament\Resources\Jobs\JobResource;
use App\Models\Attachment;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Detalle de un adjunto.
 *
 * Lo que se quiere ver cuando algo salió mal: qué detectaron los magic bytes,
 * con qué regla se clasificó, qué campos salieron y —si hubo OCR— con qué
 * motor y qué confianza.
 */
class AttachmentInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Archivo')->columns(4)->schema([
                    TextEntry::make('filename')->label('Nombre')->columnSpan(2),
                    TextEntry::make('size_bytes')
                        ->label('Tamaño')
                        ->state(fn (?Attachment $record): string => $record?->sizeForHumans() ?? '—'),
                    TextEntry::make('sha256')
                        ->label('SHA-256')
                        ->limit(20)
                        ->tooltip(fn (?Attachment $record): string => (string) $record?->sha256),

                    TextEntry::make('local_path')
                        ->label('Ruta local')
                        ->columnSpan(2)
                        ->placeholder('(el archivo no está en disco)')
                        ->color(fn (?Attachment $record): string => $record?->fileExists() ? 'gray' : 'danger'),

                    TextEntry::make('created_at')->label('Procesado')->dateTime('d/m/Y H:i'),
                ]),

                Section::make('Detección')->columns(4)->schema([
                    TextEntry::make('detected_kind')->label('Tipo real (magic bytes)')->badge(),
                    TextEntry::make('mime')->label('MIME')->placeholder('—'),
                    TextEntry::make('extension_mismatch')
                        ->label('¿Extensión correcta?')
                        ->badge()
                        ->formatStateUsing(fn (bool $state): string => $state ? 'NO · CU-04' : 'Sí')
                        ->color(fn (bool $state): string => $state ? 'danger' : 'success'),
                    TextEntry::make('processing_error')
                        ->label('Error de análisis')
                        ->placeholder('—')
                        ->color('danger')
                        ->columnSpan(1),
                ]),

                Section::make('Clasificación')->description(
                    'Regla que ganó y por qué. El desglose del score es lo que '
                    .'permite ajustar las keywords sin adivinar.'
                )->columns(4)->schema([
                    TextEntry::make('doc_type')
                        ->label('Tipo documental')
                        ->badge()
                        ->color(fn (?string $state): string => $state === null || $state === 'desconocido'
                            ? 'warning'
                            : 'success'),
                    TextEntry::make('confidence')->label('Confianza')->numeric(2)->placeholder('—'),
                    TextEntry::make('key_signals')
                        ->label('Señales')
                        ->badge()
                        ->formatStateUsing(fn (mixed $state): string => implode(', ', (array) $state))
                        ->placeholder('—')
                        ->columnSpan(2),
                    TextEntry::make('reasoning')->label('Razonamiento')->columnSpanFull(),
                    TextEntry::make('score_breakdown')
                        ->label('Desglose del score')
                        ->formatStateUsing(fn (mixed $state): string => self::prettyJson($state))
                        ->columnSpanFull(),
                ]),

                Section::make('Extracción de texto')
                    ->collapsible()
                    ->collapsed()
                    ->columns(3)
                    ->schema([
                        TextEntry::make('ocr_used')
                            ->label('¿Usó OCR?')
                            ->badge()
                            ->formatStateUsing(fn (bool $state): string => $state ? 'Sí' : 'No'),
                        TextEntry::make('ocr_engine')->label('Motor')->placeholder('—'),
                        TextEntry::make('ocr_confidence')
                            ->label('Confianza OCR')
                            ->suffix('%')
                            ->placeholder('—'),

                        TextEntry::make('fields_json')
                            ->label('Campos extraídos')
                            ->formatStateUsing(fn (mixed $state): string => self::prettyJson($state))
                            ->columnSpanFull(),

                        TextEntry::make('extracted_text')
                            ->label('Texto')
                            ->limit(3000)
                            ->placeholder('(sin texto extraído)')
                            ->columnSpanFull(),
                    ]),

                Section::make('Origen')->schema([
                    TextEntry::make('email.subject')
                        ->label('Correo')
                        ->state(fn (?Attachment $record): string => (string) $record?->email?->subject)
                        ->url(fn (?Attachment $record): ?string => $record?->email
                            ? JobResource::getUrl('view', ['record' => $record->email])
                            : null),
                ]),
            ]);
    }

    private static function prettyJson(mixed $value): string
    {
        if ($value === null || $value === [] || $value === '') {
            return '—';
        }

        return json_encode(
            is_string($value) ? json_decode($value, true) ?? $value : $value,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE,
        );
    }
}
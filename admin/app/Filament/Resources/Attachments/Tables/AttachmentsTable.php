<?php

namespace App\Filament\Resources\Attachments\Tables;

use App\Filament\Resources\Attachments\AttachmentResource;
use App\Models\Attachment;
use Illuminate\Database\Eloquent\Builder;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

/**
 * Listado de adjuntos.
 *
 * Las dos columnas que importan son «tipo real vs. extensión» y «tipo
 * documental con su confianza»: entre las dos se lee de un vistazo por qué un
 * documento quedó como `desconocido` o por qué se mandó a revisión.
 */
class AttachmentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('filename')
                    ->label('Archivo')
                    ->searchable(['filename', 'local_path'])
                    ->sortable()
                    ->limit(40)
                    // Las closures por registro se evalúan también al mapear columnas, cuando
// todavía no hay ninguna fila: por eso admiten null en vez de exigir el modelo.
->description(fn (?Attachment $record): ?string => $record?->sizeForHumans()),

                TextColumn::make('detected_kind')
                    ->label('Tipo real')
                    ->badge()
                    ->searchable()
                    ->sortable(),

                // CU-04 en una columna: la extensión que no corresponde al
                // contenido es el dato más accionable del listado.
                IconColumn::make('extension_mismatch')
                    ->label('Mismatch')
                    ->boolean()
                    ->tooltip('La extensión no corresponde al contenido real (CU-04)'),

                TextColumn::make('doc_type')
                    ->label('Tipo documental')
                    ->badge()
                    ->searchable()
                    ->sortable()
                    ->color(fn (?string $state): string => $state === null || $state === 'desconocido'
                        ? 'warning'
                        : 'success'),

                TextColumn::make('confidence')
                    ->label('Confianza')
                    ->numeric(2)
                    ->sortable()
                    ->placeholder('—'),

                TextColumn::make('ocr_used')
                    ->label('OCR')
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Sí' : 'No')
                    ->color(fn (bool $state): string => $state ? 'info' : 'gray')
                    ->description(fn (?Attachment $record): ?string => $record?->ocr_used
                        ? $record->ocr_engine.' · '.($record->ocr_confidence ?? '?').'%'
                        : null),

                TextColumn::make('processing_error')
                    ->label('Error')
                    ->badge()
                    ->color('danger')
                    ->limit(50)
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('created_at')
                    ->label('Procesado')
                    ->since()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                // Las opciones salen de los datos, pero un NULL no puede
                // ser etiqueta de un Select: se filtra y se rotula aparte.
                SelectFilter::make('detected_kind')
                    ->label('Tipo real')
                    ->options(fn (): array => self::optionsOf(Attachment::query(), 'detected_kind')),

                SelectFilter::make('doc_type')
                    ->label('Tipo documental')
                    ->options(fn (): array => self::optionsOf(Attachment::query(), 'doc_type')),

                TernaryFilter::make('extension_mismatch')
                    ->label('Solo con mismatch'),

                TernaryFilter::make('ocr_used')
                    ->label('¿Pasó por OCR?'),
            ])
            ->recordUrl(fn (Attachment $record): string => AttachmentResource::getUrl('index', [
                'tableFilters' => ['detected_kind' => ['value' => $record->detected_kind]],
            ]))
            ->emptyStateHeading('No hay adjuntos')
            ->emptyStateDescription('Aparecen cuando un correo pasa por el pipeline.');
    }

    /**
     * Opciones de un filtro, sacadas de los valores reales de la columna.
     *
     * Se descartan los NULL: `pluck()` los devuelve como clave y un Select con
     * etiqueta `null` revienta la página al renderizarse. `desconocido` se
     * mantiene porque para el usuario sí es un valor filtrable.
     *
     * @return array<string, string>
     */
    private static function optionsOf(Builder $query, string $column): array
    {
        return $query
            ->select($column)
            ->whereNotNull($column)
            ->distinct()
            ->pluck($column, $column)
            ->map(fn (string $value): string => $value === 'desconocido' ? 'desconocido (revisar)' : $value)
            ->all();
    }
}
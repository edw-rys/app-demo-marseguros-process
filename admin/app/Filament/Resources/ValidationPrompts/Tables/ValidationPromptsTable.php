<?php

namespace App\Filament\Resources\ValidationPrompts\Tables;

use App\Filament\Resources\ValidationPrompts\Actions\TestPromptAction;
use App\Models\ValidationPrompt;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

/**
 * Listado de prompts del Stack B.
 *
 * La previsualización del texto es lo que hace útil el listado: no hace falta
 * abrir cada registro para ver qué cambió respecto al de al lado.
 */
class ValidationPromptsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Nombre')
                    ->searchable()
                    ->sortable()
                    ->badge()
                    ->description(fn (?ValidationPrompt $record): string => match (true) {
                        $record === null                            => '—',
                        str_starts_with($record->name, 'classify')   => 'Clasificación',
                        str_starts_with($record->name, 'extract_')  => 'Extracción',
                        str_starts_with($record->name, 'validate_') => 'Fase 2',
                        default                                     => '—',
                    }),

                TextColumn::make('prompt_text')
                    ->label('Prompt')
                    ->wrap()
                    ->limit(110)
                    ->searchable()
                    ->tooltip(fn (?ValidationPrompt $record): string => (string) $record?->prompt_text),

                TextColumn::make('version')
                    ->label('v')
                    ->alignEnd()
                    ->sortable()
                    ->description('Sube al editar el texto'),

                IconColumn::make('active')
                    ->label('Activo')
                    ->boolean()
                    ->sortable(),

                TextColumn::make('updated_at')
                    ->label('Editado')
                    ->since()
                    ->sortable(),
            ])
            ->defaultSort('name')
            ->filters([
                SelectFilter::make('kind')
                    ->label('Tipo')
                    ->options([
                        'classify'  => 'Clasificación',
                        'extract'   => 'Extracción',
                        'validate'  => 'Fase 2',
                    ])
                    ->query(function ($query, array $data): void {
                        match (true) {
                            ($data['value'] ?? null) === 'classify' => $query->where('name', 'classify'),
                            ($data['value'] ?? null) === 'extract'  => $query->where('name', 'like', 'extract\_%'),
                            ($data['value'] ?? null) === 'validate' => $query->where('name', 'like', 'validate\_%'),
                            default => null,
                        };
                    }),

                TernaryFilter::make('active')
                    ->label('Solo activos')
                    ->default(true),
            ])
            ->recordActions([
                EditAction::make(),
                TestPromptAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('No hay prompts')
            ->emptyStateDescription('Cargá el juego de ejemplo con: php artisan demo:seed');
    }
}
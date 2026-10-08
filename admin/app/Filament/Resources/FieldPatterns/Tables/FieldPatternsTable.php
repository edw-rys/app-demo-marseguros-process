<?php

namespace App\Filament\Resources\FieldPatterns\Tables;

use App\Models\FieldPattern;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

/**
 * Listado de regex.
 *
 * La columna de regex muestra además si compila: un patrón roto guardado hace
 * tiempo es la causa más común de que un campo deje de extraerse sin que nada
 * falle visiblemente.
 */
class FieldPatternsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Campo')
                    ->searchable()
                    ->sortable()
                    ->description(fn (?FieldPattern $record): ?string => $record?->description),

                TextColumn::make('regex')
                    ->label('Regex')
                    ->badge()
                    ->searchable()
                    ->color(fn (string $state): string => FieldPattern::isValidRegex($state)
                        ? 'gray'
                        : 'danger')
                    ->formatStateUsing(fn (string $state): string => FieldPattern::isValidRegex($state)
                        ? $state
                        : '⚠ no compila: '.$state)
                    ->tooltip(fn (?FieldPattern $record): string => FieldPattern::isValidRegex($record?->regex ?? '')
                        ? 'El worker compila este patrón con IGNORECASE|MULTILINE.'
                        : 'Este regex no compila: el worker lo va a descartar.'),

                IconColumn::make('active')
                    ->label('Activo')
                    ->boolean()
                    ->sortable(),

                TextColumn::make('version')
                    ->label('v')
                    ->alignEnd()
                    ->sortable(),

                TextColumn::make('updated_at')
                    ->label('Editado')
                    ->since()
                    ->sortable(),
            ])
            ->defaultSort('name')
            ->filters([
                TernaryFilter::make('active')
                    ->label('Solo activos')
                    ->default(true),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('No hay patrones de campo')
            ->emptyStateDescription('Cargá el juego de ejemplo con: php artisan demo:seed');
    }
}
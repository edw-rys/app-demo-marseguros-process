<?php

namespace App\Filament\Resources\DocTypeRules\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Listado de reglas de tipo documental.
 *
 * Se ven de un vistazo tres cosas: cuáles están activas, qué tan fuerte es la
 * regla (cuántas keywords tiene) y qué tan bien pondera. El filtro por `active`
 * es el que se usa a diario: desactivar una regla que está falsos-positivando
 * debe ser una operación de un clic.
 */
class DocTypeRulesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('label')
                    ->label('Tipo')
                    ->searchable(['label', 'name'])
                    ->sortable()
                    ->description(fn ($record): ?string => $record->label === null ? null : $record->name),

                TextColumn::make('required_keywords')
                    ->label('Obligatorias')
                    ->badge()
                    ->color('danger')
                    ->formatStateUsing(fn ($state): string => count((array) $state).' oblig.')
                    ->description(fn ($record): string => self::preview((array) $record->required_keywords))
                    ->sortable(false),

                TextColumn::make('keywords')
                    ->label('Refuerzo')
                    ->badge()
                    ->color('warning')
                    ->formatStateUsing(fn ($state): string => count((array) $state).' bonus')
                    ->description(fn ($record): string => self::preview((array) $record->keywords))
                    ->sortable(false),

                TextColumn::make('filename_hints')
                    ->label('Nombre')
                    ->badge()
                    ->color('info')
                    ->formatStateUsing(fn ($state): string => count((array) $state).' hint(s)')
                    ->sortable(false),

                IconColumn::make('active')
                    ->label('Activa')
                    ->boolean()
                    ->sortable(),

                TextColumn::make('version')
                    ->label('v')
                    ->alignEnd()
                    ->sortable(),

                TextColumn::make('updated_at')
                    ->label('Editada')
                    ->since()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('label')
            ->filters([
                \Filament\Tables\Filters\TernaryFilter::make('active')
                    ->label('Solo activas')
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
            ->emptyStateHeading('No hay reglas de tipo')
            ->emptyStateDescription(
                'Cargá el juego de ejemplo con: php artisan demo:seed'
            );
    }

    /** @param array<int, string> $items */
    private static function preview(array $items): string
    {
        if ($items === []) {
            return '—';
        }

        $shown = implode(', ', array_slice($items, 0, 4));

        return count($items) > 4
            ? $shown.' +'.(count($items) - 4)
            : $shown;
    }
}
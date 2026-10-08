<?php

namespace App\Filament\Resources\ValidationRules\Tables;

use App\Models\ValidationRule;
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
 * Listado de reglas de Fase 2.
 *
 * La columna de condiciones se muestra en resumen: leer el JSON entero en un
 * listado no sirve, pero sí saber de un vistazo si una regla compara contra
 * una fecha, contra un identificador o contra un monto.
 */
class ValidationRulesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Regla')
                    ->searchable()
                    ->sortable()
                    ->description(fn (?ValidationRule $record): ?string => $record?->json_rule['code'] ?? null),

                TextColumn::make('severity')
                    ->label('Severidad')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => $state === 'error' ? 'Error' : 'Warning')
                    ->color(fn (string $state): string => $state === 'error' ? 'danger' : 'warning')
                    ->sortable(),

                TextColumn::make('doc_types')
                    ->label('Tipos')
                    ->badge()
                    ->color('info')
                    ->formatStateUsing(fn (mixed $state): string => (array) $state === []
                        ? 'Todos'
                        : implode(', ', (array) $state))
                    ->sortable(false),

                // El texto se arma en una sola closure: si `state()` devuelve el array y el
                // formateo vive aparte, Filament le pasa el array ya casteado a
                // string y revienta el `implode`.
                TextColumn::make('_facts')
                    ->label('Sobre qué facts')
                    ->badge()
                    ->color('gray')
                    ->state(fn (?ValidationRule $record): string => ($facts = self::factsOf($record)) === []
                        ? '—'
                        : implode(', ', $facts))
                    ->toggleable(),

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
                    ->sortable(),
            ])
            ->defaultSort('name')
            ->filters([
                SelectFilter::make('severity')
                    ->label('Severidad')
                    ->options([
                        'error'   => 'Error — bloquea',
                        'warning' => 'Warning — solo avisa',
                    ]),

                TernaryFilter::make('active')
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
            ->emptyStateHeading('No hay reglas de validación')
            ->emptyStateDescription('Cargá el juego de ejemplo con: php artisan demo:seed');
    }

    /**
     * Facts referenciados por la regla.
     *
     * Se recogen del JSON andando a profundidad porque la estructura anida
     * (`conditions.all[].fact`) y no hay un campo fijo donde mirarlos.
     *
     * @return array<int, string>
     */
    private static function factsOf(?ValidationRule $rule): array
    {
        $facts = [];

        if ($rule === null) {
            return [];
        }

        $walk = function (mixed $node) use (&$walk, &$facts): void {
            if (! is_array($node)) {
                return;
            }

            if (isset($node['fact']) && is_string($node['fact'])) {
                $facts[$node['fact']] = true;
            }

            foreach ($node as $child) {
                $walk($child);
            }
        };

        $walk($rule->json_rule);

        return array_keys($facts);
    }
}
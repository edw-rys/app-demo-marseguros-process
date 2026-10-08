<?php

namespace App\Filament\Resources\ValidationResults\Tables;

use App\Filament\Resources\Jobs\JobResource;
use App\Models\ValidationResult;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

/**
 * Listado de resultados de validación.
 *
 * El filtro por defecto es «solo los que fallaron»: es la pregunta que se
 * hace el 90 % de las veces al abrir este menú.
 */
class ValidationResultsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('status')
                    ->label('Veredicto')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => self::statusLabel($state))
                    ->color(fn (string $state): string => self::statusColor($state))
                    ->sortable(),

                TextColumn::make('rule_name')
                    ->label('Regla')
                    ->searchable()
                    ->sortable()
                    ->description(fn (?ValidationResult $record): ?string => $record?->code),

                TextColumn::make('severity')
                    ->label('Severidad')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => self::severityLabel($state))
                    ->color(fn (?string $state): string => $state === 'error' ? 'danger' : 'warning')
                    ->sortable(),

                TextColumn::make('message')
                    ->label('Mensaje')
                    ->wrap()
                    ->limit(80)
                    ->placeholder('—'),

                TextColumn::make('email.subject')
                    ->label('Correo')
                    ->limit(36)
                    ->placeholder('—')
                    ->url(fn (?ValidationResult $record): ?string => $record?->email
                        ? JobResource::getUrl('view', ['record' => $record->email])
                        : null),

                TextColumn::make('created_at')
                    ->label('Evaluado')
                    ->since()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label('Veredicto')
                    ->options([
                        'pass'  => 'Pass',
                        'warn'  => 'Warn',
                        'fail'  => 'Fail',
                        'error' => 'Error',
                    ])
                    ->default('fail'),

                SelectFilter::make('severity')
                    ->label('Severidad')
                    ->options([
                        'error'   => 'Error (bloquea el trámite)',
                        'warning' => 'Warning (solo avisa)',
                    ]),

                TernaryFilter::make('has_code')
                    ->label('¿Con código?')
                    ->query(fn ($query, array $data) => $query->when(
                        ($data['state'] ?? null) !== null,
                        fn ($q) => ($data['state'] ?? null) === true
                            ? $q->whereNotNull('code')
                            : $q->whereNull('code'),
                    )),
            ])
            ->recordUrl(fn (ValidationResult $record): string => JobResource::getUrl('view', [
                'record' => $record->email,
            ]))
            ->emptyStateHeading('Sin resultados de validación')
            ->emptyStateDescription('Aparecen cuando un correo pasa por la Fase 2.');
    }

    private static function statusLabel(string $status): string
    {
        return match ($status) {
            'pass'  => 'Pass',
            'warn'  => 'Warn',
            'fail'  => 'Fail',
            'error' => 'Error',
            default => $status,
        };
    }

    private static function statusColor(string $status): string
    {
        return match ($status) {
            'pass'  => 'success',
            'warn'  => 'warning',
            'fail'  => 'danger',
            'error' => 'danger',
            default => 'gray',
        };
    }

    private static function severityLabel(?string $severity): string
    {
        return match ($severity) {
            'error'   => 'Error',
            'warning' => 'Warning',
            null      => '—',
            default   => $severity,
        };
    }
}
<?php

namespace App\Filament\Resources\LlmCalls\Tables;

use App\Filament\Resources\Jobs\JobResource;
use App\Models\LlmCall;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

/**
 * Listado de llamadas al LLM.
 *
 * Lo primero que se mira es `degraded`: marca las filas en las que OpenRouter
 * falló y el pipeline siguió con Stack A. Es la señal de que hay que mirar la
 * key, el saldo o la cuota.
 */
class LlmCallsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('purpose')
                    ->label('Propósito')
                    ->badge()
                    ->searchable()
                    ->sortable()
                    ->description(fn (?LlmCall $record): ?string => $record?->prompt_name),

                TextColumn::make('model')
                    ->label('Modelo')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('prompt_tokens')
                    ->label('Tokens in')
                    ->numeric()
                    ->sortable()
                    ->alignEnd(),

                TextColumn::make('completion_tokens')
                    ->label('Tokens out')
                    ->numeric()
                    ->sortable()
                    ->alignEnd(),

                TextColumn::make('cost_usd')
                    ->label('Costo')
                    ->money('USD')
                    ->sortable()
                    ->alignEnd()
                    ->summarize(
                        \Filament\Tables\Columns\Summarizers\Sum::make()
                            ->money('USD')
                            ->label('Total'),
                    ),

                TextColumn::make('latency_ms')
                    ->label('Latencia')
                    ->state(fn (?LlmCall $record): ?string => $record === null
                        ? null
                        : ($record->latency_ms < 1000
                            ? $record->latency_ms.' ms'
                            : round($record->latency_ms / 1000, 2).' s'))
                    ->sortable()
                    ->alignEnd(),

                IconColumn::make('cache_hit')
                    ->label('Cache')
                    ->boolean()
                    ->tooltip('Respondido desde analysis_cache (F-12), sin gastar tokens'),

                IconColumn::make('degraded')
                    ->label('Degradado')
                    ->boolean()
                    ->color('danger')
                    ->tooltip('OpenRouter falló y el pipeline siguió con Stack A (CU-05)'),

                TextColumn::make('error')
                    ->label('Error')
                    ->color('danger')
                    ->limit(40)
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('created_at')
                    ->label('Cuándo')
                    ->since()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('purpose')
                    ->label('Propósito')
                    ->options(fn (): array => LlmCall::query()
                        ->select('purpose')
                        ->whereNotNull('purpose')
                        ->distinct()
                        ->pluck('purpose', 'purpose')
                        ->all()),

                TernaryFilter::make('degraded')
                    ->label('¿Degradadas?')
                    ->default(true),

                TernaryFilter::make('cache_hit')
                    ->label('¿Desde cache?'),
            ])
            ->recordUrl(fn (LlmCall $record): string => JobResource::getUrl('view', [
                'record' => $record->email,
            ]))
            ->emptyStateHeading('Sin llamadas al LLM')
            ->emptyStateDescription('Aparecen cuando algún job se procesa con Stack B.');
    }
}
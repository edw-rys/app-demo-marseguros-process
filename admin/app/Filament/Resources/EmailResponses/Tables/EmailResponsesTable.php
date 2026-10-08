<?php

namespace App\Filament\Resources\EmailResponses\Tables;

use App\Filament\Resources\Jobs\JobResource;
use App\Models\EmailResponse;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Listado de respuestas.
 *
 * La columna que importa es `status`: en el demo todas deberían estar en
 * `dry_run`, y ver `sent` significa que `GMAIL_SEND_ENABLED` está en true.
 */
class EmailResponsesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('template')
                    ->label('Plantilla')
                    ->badge()
                    ->searchable()
                    ->sortable(),

                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->color(fn (?EmailResponse $record): string => $record?->statusColor() ?? 'gray')
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'sent'    => 'Enviada',
                        'dry_run' => 'Dry run',
                        'failed'  => 'Falló',
                        default   => $state,
                    })
                    ->sortable(),

                TextColumn::make('source')
                    ->label('Origen')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => $state === 'llm' ? 'LLM' : 'Template')
                    ->color(fn (string $state): string => $state === 'llm' ? 'info' : 'gray')
                    ->sortable(),

                TextColumn::make('body')
                    ->label('Texto')
                    ->wrap()
                    ->limit(90)
                    ->tooltip(fn (?EmailResponse $record): string => (string) $record?->body),

                TextColumn::make('email.subject')
                    ->label('Correo')
                    ->limit(34)
                    ->placeholder('—')
                    ->url(fn (?EmailResponse $record): ?string => $record?->email
                        ? JobResource::getUrl('view', ['record' => $record->email])
                        : null),

                TextColumn::make('sent_at')
                    ->label('Enviado')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->sortable(),

                TextColumn::make('error')
                    ->label('Error')
                    ->color('danger')
                    ->limit(40)
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label('Estado')
                    ->options([
                        'dry_run' => 'Dry run (generada, no enviada)',
                        'sent'    => 'Enviada',
                        'failed'  => 'Falló',
                    ]),
            ])
            ->recordUrl(fn (EmailResponse $record): string => JobResource::getUrl('view', [
                'record' => $record->email,
            ]))
            ->emptyStateHeading('Sin respuestas')
            ->emptyStateDescription('Aparecen cuando un job llega a la etapa `reply`.');
    }
}
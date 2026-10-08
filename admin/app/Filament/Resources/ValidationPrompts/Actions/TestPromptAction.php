<?php

namespace App\Filament\Resources\ValidationPrompts\Actions;

use App\Models\ValidationPrompt;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Http;

/**
 * "Probar prompt": manda el texto a Stack B y muestra el JSON que volvió.
 *
 * Es el botón que hace falta antes de confiar un prompt con un job real: sin
 * esto, un prompt roto se descubre cuando el trámite entero sale mal.
 *
 * La llamada va a `/test-prompt`, que no crea jobs ni toca la base del worker:
 * es una ida y vuelta pura al LLM.
 */
class TestPromptAction
{
    public static function make(): Action
    {
        return Action::make('testPrompt')
            ->label('Probar prompt')
            ->icon('heroicon-o-play')
            ->color('info')
            // Confirmación porque la llamada gasta tokens de verdad.
            ->requiresConfirmation()
            ->modalHeading('Probar este prompt')
            ->modalDescription(
                'Se manda el texto a OpenRouter tal cual y se muestra el JSON que '
                .'devuelva. Gasta tokens, pero no crea ningún job ni modifica nada.'
            )
            ->action(function (ValidationPrompt $record): void {
                self::run($record);
            });
    }

    private static function run(ValidationPrompt $prompt): void
    {
        $config = config('gmail_docs.workers.b');
        $url = $config['url'].'/test-prompt';

        if (config('gmail_docs.token') === null || config('gmail_docs.token') === '') {
            Notification::make()
                ->title('Falta ANALYZER_TOKEN')
                ->body('El admin no tiene token para hablar con el worker. '
                    .'Revisá admin/.env.')
                ->danger()
                ->send();

            return;
        }

        try {
            $response = Http::withToken((string) config('gmail_docs.token'))
                ->timeout((int) config('gmail_docs.timeout'))
                ->acceptJson()
                ->post($url, ['prompt_text' => $prompt->prompt_text]);
        } catch (\Throwable $e) {
            Notification::make()
                ->title('No se pudo contactar a Stack B')
                ->body($e->getMessage())
                ->danger()
                ->send();

            return;
        }

        if (! $response->successful()) {
            $detail = $response->json('error') ?? $response->json('detail') ?? $response->body();

            Notification::make()
                ->title('Stack B respondió '.($response->status()))
                ->body(\Illuminate\Support\Str::limit((string) $detail, 300))
                ->danger()
                ->send();

            return;
        }

        $result = $response->json('result');
        $traces = $response->json('traces', []);

        $tokens = 0;
        $cost = 0.0;
        foreach ($traces as $trace) {
            $tokens += (int) ($trace['prompt_tokens'] ?? 0) + (int) ($trace['completion_tokens'] ?? 0);
            $cost += (float) ($trace['cost_usd'] ?? 0);
        }

        Notification::make()
            ->title('Respuesta del LLM')
            ->body(
                ($tokens > 0 ? "{$tokens} tokens · \$".number_format($cost, 6)."\n\n" : '')
                .json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
            )
            ->success()
            // El JSON completo puede ser largo: se deja desplegar.
            ->persistent()
            ->send();
    }
}
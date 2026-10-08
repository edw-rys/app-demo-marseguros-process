<?php

namespace App\Filament\Resources\FieldPatterns\Schemas;

use App\Models\FieldPattern;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

/**
 * Formulario de regex de extracción.
 *
 * El regex se valida con `FieldPattern::isValidRegex()` ANTES de guardar, y se
 * prueba contra un texto de muestra. Es lo que evita el peor modo de falla de
 * esta pantalla: guardar un patrón sintácticamente válido que en realidad nunca
 * casa con nada, y enterarse semanas después cuando nadie documenta un RUC.
 */
class FieldPatternForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Patrón')->columns(2)->schema([
                    TextInput::make('name')
                        ->label('Nombre del campo')
                        ->required()
                        ->maxLength(60)
                        ->unique(ignoreRecord: true)
                        ->helperText(
                            'Con sufijo `ruc` o `rfc` se autocompleta el validador: '
                            .'`ruc_emisor` produce `ruc_emisor_valid`.'
                        ),

                    TextInput::make('description')
                        ->label('Descripción')
                        ->maxLength(200)
                        ->placeholder('RUC del emisor de la factura'),

                    Textarea::make('regex')
                        ->label('Regex')
                        ->required()
                        ->rows(3)
                        ->columnSpanFull()
                        ->helperText('PCRE, sin delimitadores. Se compila con IGNORECASE|MULTILINE.')
                        // Se valida mientras se escribe: el error aparece en el
                        // campo, no como una excepción al guardar.
                        ->rule(fn (): \Closure => function (string $attribute, mixed $value, \Closure $fail): void {
                            if (! FieldPattern::isValidRegex($value === null ? null : (string) $value)) {
                                $fail('El regex no compila. Revisa los paréntesis, corchetes y cuantificadores.');
                            }
                        }),

                    Toggle::make('active')
                        ->label('Activo')
                        ->default(true)
                        ->helperText('Solo los activos se envían al worker.'),

                    TextInput::make('version')
                        ->label('Versión')
                        ->numeric()
                        ->default(1)
                        ->required(),
                ]),

                Section::make('Probar contra un texto')
                    ->description(
                        'Pega un fragmento de un documento real y mira qué saldría. '
                        .'Es la forma rápida de saber si el patrón sirve antes de '
                        .'esperar al próximo job.'
                    )
                    ->schema([
                        Textarea::make('_sample')
                            ->label('Texto de muestra')
                            ->rows(5)
                            ->dehydrated(false)
                            ->live()
                            ->placeholder('RUC emisor: 1790012345001'),

                        // `Placeholder` y no un Textarea: es una salida, no un campo
                        // editable, y así no hay que pelear con el estado.
                        Placeholder::make('_preview')
                            ->label('Lo que se extraería')
                            ->content(fn (Get $get): HtmlString => new HtmlString(
                                e(self::preview($get)) ?: '<span class="text-gray-400">—</span>',
                            )),
                    ]),
            ]);
    }

    /**
     * Corre el regex del formulario sobre el texto de muestra.
     *
     * Se lee con `Get` porque los dos campos son `dehydrated(false)`: no forman
     * parte del modelo y solo existen para esta vista previa.
     */
    public static function preview(Get $get): string
    {
        $regex = $get('regex');
        $sample = $get('_sample');

        if (! is_string($regex) || $regex === '' || ! is_string($sample) || $sample === '') {
            return '';
        }

        if (! FieldPattern::isValidRegex($regex)) {
            return '⚠ El regex no compila.';
        }

        // Se compila sin el delimitador de PCRE, con los mismos flags que usa
        // `stack-a/app/fields.py`.
        $compiled = @preg_match_all('~'.$regex.'~imu', $sample, $matches);

        if ($compiled === false || ($matches[1] ?? []) === []) {
            return 'Sin coincidencias en el texto de muestra.';
        }

        $values = array_values(array_filter(
            $matches[1] !== [] ? $matches[1] : $matches[0],
            static fn (string $v): bool => trim($v) !== '',
        ));

        if ($values === []) {
            return 'Sin coincidencias en el texto de muestra.';
        }

        return $compiled === 1
            ? $values[0]
            : implode("\n", $values);
    }
}
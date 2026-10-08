<?php

namespace App\Filament\Resources\DocTypeRules\Schemas;

use App\Models\DocTypeRule;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/**
 * Formulario de reglas de tipo documental.
 *
 * Las keywords van en `TagsInput` y no en un textarea con comas porque el
 * scorer de `stack-a/app/classify.py` las trata como lista; escribir
 * "factura, comprobante" como un solo item rompería el matching silenciosamente.
 */
class DocTypeRuleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Identificación')->columns(2)->schema([
                    TextInput::make('name')
                        ->label('Nombre')
                        ->required()
                        ->maxLength(60)
                        ->unique(ignoreRecord: true)
                        ->helperText('Identificador interno. Se usa en las reglas de Fase 2.')
                        // El nombre es la clave con la que el worker decide
                        // qué reglas y qué prompts aplicar: renombrarlo rompe
                        // los vínculos, así que no se toca después de creado.
                        ->disabled(fn (string $operation): bool => $operation === 'edit'),

                    TextInput::make('label')
                        ->label('Etiqueta visible')
                        ->maxLength(120)
                        ->placeholder('Factura de venta'),

                    Toggle::make('active')
                        ->label('Activa')
                        ->default(true)
                        ->helperText('Las inactivas no se envían al worker.'),

                    TextInput::make('version')
                        ->label('Versión')
                        ->numeric()
                        ->default(1)
                        ->required(),
                ]),

                Section::make('Señales de clasificación')
                    ->description(
                        'El score de stack-a es 0.5·required + 0.3·bonus + 0.2·filename. '
                        .'Por debajo de 0.4 el documento queda como «desconocido».'
                    )
                    ->schema([
                        TagsInput::make('required_keywords')
                            ->label('Keywords obligatorias')
                            ->placeholder('Escriba una keyword y pulse Enter')
                            ->helperText(
                                'Si el documento NO contiene ninguna, la regla no aplica '
                                .'(peso 0.5: es lo que descarta falsos positivos).'
                            )
                            ->columnSpanFull(),

                        TagsInput::make('keywords')
                            ->label('Keywords de refuerzo')
                            ->placeholder('Escriba una keyword y pulse Enter')
                            ->helperText('Suman score pero no son obligatorias (peso 0.3).')
                            ->columnSpanFull(),

                        TagsInput::make('filename_hints')
                            ->label('Pistas en el nombre de archivo')
                            ->placeholder('factura')
                            ->helperText(
                                'Se buscan en minúsculas dentro del nombre del adjunto (peso 0.2).'
                            )
                            ->columnSpanFull(),

                        Textarea::make('_score')
                            ->label('Score resultante')
                            ->disabled()
                            ->dehydrated(false)
                            ->rows(7)
                            ->columnSpanFull()
                            // Se recalcula en vivo mientras se edita el
                            // formulario: quien cambia una keyword ve al
                            // instante si el documento seguiría clasificándose.
                            //
                            // El estado de este campo es null hasta que algo
                            // escriba en él, así que se leen los tres campos
                            // vecinos por su nombre en vez de usar `$state`.
                            ->live()
                            ->formatStateUsing(fn (mixed $state, Get $get): string => self::explain(
                                $get('required_keywords') ?? [],
                                $get('keywords') ?? [],
                                $get('filename_hints') ?? [],
                            )),
                    ]),
            ]);
    }

    /**
     * Traduce la regla a la aritmética real del scorer.
     *
     * Sirve para que quien edita la regla vea si un cambio sube o baja el
     * listón, sin tener que leer `classify.py`.
     *
     * @param  array<int, string>  $required
     * @param  array<int, string>  $bonus
     * @param  array<int, string>  $hints
     */
    private static function explain(array $required, array $bonus, array $hints): string
    {
        $bonusCount = count($bonus);
        $bonusScore = $bonusCount === 0 ? 0.0 : 0.3 / $bonusCount;
        $filenameScore = $hints === [] ? 0.0 : 0.2;
        $max = 0.5 + $bonusScore + $filenameScore;

        $out = "Si el documento contiene TODAS las obligatorias y 1 de refuerzo:\n";
        $out .= '  0.50  required completas'."\n";
        $out .= '  +'.number_format($bonusScore, 2).'  refuerzo (1 de '.$bonusCount.')'."\n";
        $out .= '  +'.number_format($filenameScore, 2).'  el nombre contiene una pista'."\n\n";
        $out .= 'Máximo posible: '.number_format($max, 2)
            .'   ·   Umbral de «desconocido»: 0.40'."\n";
        $out .= "\nRefuerzo: ".($bonus === [] ? 'ninguna' : implode(', ', $bonus));
        $out .= "\nPistas de nombre: ".($hints === [] ? 'ninguna' : implode(', ', $hints));

        if ($max < 0.4) {
            $out .= "\n\n⚠ Con esta configuración el score NUNCA alcanza el umbral: "
                .'todo se clasificará como «desconocido».';
        }

        if ($required === []) {
            $out .= "\n\n⚠ Sin keywords obligatorias la regla no descarta nada: "
                .'cualquier documento puede puntuar alto con esta regla.';
        }

        return $out;
    }
}
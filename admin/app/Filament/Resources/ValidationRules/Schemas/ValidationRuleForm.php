<?php

namespace App\Filament\Resources\ValidationRules\Schemas;

use App\Models\ValidationRule;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

/**
 * Formulario de reglas de Fase 2.
 *
 * El JSON de la regla se edita como texto, no como formulario anidado: la
 * sintaxis es de `json-rules-engine` y construirla con campos obligaría a
 * inventar un builder para algo que ya se escribe entero en un bloque.
 *
 * Lo que sí se valida en vivo es que el JSON parsea y que tenga `conditions`:
 * un JSON roto aquí se traduce en un job que se cae en Fase 2.
 */
class ValidationRuleForm
{
    /** Facts derivados que `stack-a` inyecta y las reglas pueden referenciar. */
    public const CUSTOM_FACTS = [
        'days_since_date',
        'days_since_fecha_emision',
        'days_since_fecha_fin_vigencia',
        'ruc_emisor_valid',
        'rfc_solicitante_valid',
        'monto_in_range',
    ];

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
                        ->helperText('Identificador; aparece en cada fila de resultados.'),

                    Select::make('severity')
                        ->label('Severidad')
                        ->required()
                        ->default('error')
                        ->options([
                            'error'   => 'Error — bloquea el trámite',
                            'warning' => 'Warning — solo avisa',
                        ])
                        ->helperText('Solo `error` marca el job como rechazado.'),

                    TagsInput::make('doc_types')
                        ->label('Tipos de documento')
                        ->helperText('Vacío = la regla aplica a todos los tipos.')
                        ->placeholder('factura')
                        ->columnSpanFull(),

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

                Section::make('Regla JSON')
                    ->description('Sintaxis de json-rules-engine (node).')
                    ->schema([
                        Textarea::make('json_rule')
                            ->label('Definición de la regla')
                            ->rows(14)
                            ->required()
                            ->columnSpanFull()
                            // `json_rule` es una columna JSON (array), pero se
                            // edita como texto: al cargar se formatea y al
                            // guardar se deserializa.
                            ->default(self::blankRule())
                            ->formatStateUsing(fn (mixed $state): string => is_string($state)
                                ? $state
                                : (json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: ''))
                            ->dehydrateStateUsing(fn (?string $state): ?array => self::decode($state))
                            ->rule(fn (): \Closure => function (string $attribute, mixed $value, \Closure $fail): void {
                                $decoded = self::decode(is_string($value) ? $value : json_encode($value));

                                if ($decoded === null) {
                                    $fail('El JSON no es válido.');

                                    return;
                                }

                                if (! isset($decoded['conditions'])) {
                                    $fail('Falta la clave `conditions`.');
                                }
                            }),
                    ]),

                Section::make('Facts disponibles')
                    ->description(
                        'Además de lo extraído del documento, el worker calcula '
                        .'estos facts y las reglas pueden referenciarlos.'
                    )
                    ->collapsible()
                    ->collapsed()
                    ->schema([
                        Placeholder::make('_facts')
                            ->label('')
                            ->content(new HtmlString(
                                '<ul class="list-disc pl-4 text-sm space-y-1">'
                                .implode('', array_map(
                                    static fn (string $f): string => '<li><code>'.$f.'</code></li>',
                                    self::CUSTOM_FACTS,
                                ))
                                .'</ul>',
                            )),
                    ]),
            ]);
    }

    /** Plantilla de arranque, con la forma que espera `json-rules-engine`. */
    private static function blankRule(): string
    {
        return <<<'JSON'
        {
          "conditions": {
            "all": [
              {
                "fact": "days_since_date",
                "operator": "greaterThan",
                "value": 90
              }
            ]
          },
          "message": "El documento tiene más de 90 días.",
          "code": "DOCUMENTO_ANTIGUO"
        }
        JSON;
    }

    /**
     * Decodifica el texto del textarea a array.
     *
     * Devuelve `null` si no es JSON válido, que es como el callback de
     * validación distingue "roto" de "vacío".
     */
    private static function decode(mixed $raw): ?array
    {
        if (is_array($raw)) {
            return $raw;
        }

        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }
}
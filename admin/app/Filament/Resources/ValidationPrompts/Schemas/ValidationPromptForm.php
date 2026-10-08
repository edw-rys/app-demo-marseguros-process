<?php

namespace App\Filament\Resources\ValidationPrompts\Schemas;

use App\Models\ValidationPrompt;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

/**
 * Formulario de prompts del Stack B.
 *
 * La lista de `name` no es libre: el worker busca prompts con nombres
 * concretos (`classify`, `extract_factura_v1`, `validate_factura_v1`…). Un
 * prompt con un nombre que el worker nunca busca es un prompt que nunca se
 * ejecuta, así que se ofrece un `select` con los nombres que el código lee en
 * lugar de un campo libre — y se explica por qué.
 */
class ValidationPromptForm
{
    /**
     * Nombres que `stack-b` busca. Se sacaron de `ValidationPromptSeeder` y de
     * los `doc_type_rules`: el worker arma `extract_{doc_type}_v1` con el tipo
     * que detectó, así que los nombres tienen que seguir a las reglas de tipo.
     *
     * @var array<string, string>
     */
    public const KNOWN_NAMES = [
        'classify'                         => 'Clasificación del documento',
        'extract_factura_v1'               => 'Extracción · factura',
        'extract_ine_v1'                   => 'Extracción · INE',
        'extract_comprobante_domicilio_v1' => 'Extracción · comprobante de domicilio',
        'extract_poliza_vigente_v1'        => 'Extracción · póliza vigente',
        'extract_solicitud_v1'             => 'Extracción · solicitud',
        'extract_rfc_v1'                   => 'Extracción · RFC',
        'extract_estado_cuenta_v1'         => 'Extracción · estado de cuenta',
        'extract_acta_constitutiva_v1'     => 'Extracción · acta constitutiva',
        'validate_factura_v1'              => 'Fase 2 · factura',
        'validate_ine_v1'                  => 'Fase 2 · INE',
        'validate_comprobante_domicilio_v1' => 'Fase 2 · comprobante de domicilio',
        'validate_poliza_vigente_v1'       => 'Fase 2 · póliza vigente',
        'validate_solicitud_v1'            => 'Fase 2 · solicitud',
        'validate_rfc_v1'                  => 'Fase 2 · RFC',
        'validate_estado_cuenta_v1'        => 'Fase 2 · estado de cuenta',
        'validate_acta_constitutiva_v1'    => 'Fase 2 · acta constitutiva',
        'validate_default_v1'              => 'Fase 2 · genérico (fallback)',
    ];

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Identificación')->columns(3)->schema([
                    Select::make('name')
                        ->label('Nombre')
                        ->required()
                        ->options(self::KNOWN_NAMES)
                        ->native(false)
                        ->live()
                        ->columnSpan(2)
                        ->helperText(
                            'El worker busca estos nombres exactos. Un nombre distinto '
                            .'no se ejecutaría nunca.'
                        )
                        // El `name` vincula el prompt con los tipos documentales:
                        // renombrarlo rompe el vínculo, así que no se toca.
                        ->disabled(fn (string $operation): bool => $operation === 'edit'),

                    Toggle::make('active')
                        ->label('Activo')
                        ->default(true)
                        ->helperText('Solo los activos se envían al worker.'),

                    TextInput::make('version')
                        ->label('Versión')
                        ->numeric()
                        ->default(1)
                        ->required()
                        ->helperText('Se sube sola al cambiar el texto.'),
                ]),

                Section::make('Prompt')->schema([
                    Textarea::make('prompt_text')
                        ->label('Texto del prompt')
                        ->rows(14)
                        ->required()
                        ->columnSpanFull()
                        ->placeholder(self::example())
                        ->helperText(
                            'La PII del documento llega anonimizada (RNF-03): el RUC se '
                            .'conserva, cédulas, emails y nombres no. El nombre del tipo '
                            .'documental se interpola con {doc_type}.'
                        ),

                    \Filament\Forms\Components\Placeholder::make('_chars')
                        ->label('')
                        ->content(fn (ValidationPrompt $record): HtmlString => new HtmlString(
                            '<span class="text-sm text-gray-500">'
                            .strlen((string) $record->prompt_text)
                            .' caracteres</span>',
                        )),
                ]),

                Section::make('Esquema esperado')->collapsible()->collapsed()->schema([
                    Textarea::make('expected_schema')
                        ->label('JSON que se espera de vuelta')
                        ->rows(7)
                        ->columnSpanFull()
                        ->formatStateUsing(fn (mixed $state): string => is_string($state)
                            ? $state
                            : (json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: ''))
                        ->dehydrateStateUsing(fn (?string $state): ?array => $state === null || trim($state) === ''
                            ? null
                            : json_decode($state, true))
                        ->rule(fn (): \Closure => function (string $attribute, mixed $value, \Closure $fail): void {
                            if (! is_string($value) || trim($value) === '') {
                                return;
                            }

                            if (json_decode($value, true) === null) {
                                $fail('El esquema no es un JSON válido.');
                            }
                        }),
                ]),
            ]);
    }

    private static function example(): string
    {
        return <<<'TXT'
        Revisa la factura y decide si el trámite puede continuar.

        Criterios:
        - El RUC del emisor debe tener 13 dígitos y dígito verificador válido.
        - La fecha de emisión no puede tener más de 90 días.
        - El monto total debe ser mayor a cero.

        Responde solo con este JSON:
        {"approved": true|false, "issues": [{"code": "CODIGO", "severity": "error|warning", "message": "..."}]}
        TXT;
    }
}
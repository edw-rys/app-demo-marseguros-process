<?php

namespace Tests\Unit;

use App\Models\DocTypeRule;
use App\Support\DocumentTypes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Los keys de `required_documents` son para comparar; lo que se muestra es un
 * título. Este test fija las dos mitades: que los keys conocidos se traduzcan,
 * y que uno desconocido NO se muestre crudo.
 */
class DocumentTypesTest extends TestCase
{
    use RefreshDatabase;
    public function test_traduce_los_keys_de_los_requisitos_de_cada_corredor(): void
    {
        // Los tres corredores de `gmail_docs.required_documents`, para que
        // agregar un key ahí sin agregarlo acá lo delate.
        foreach (config('gmail_docs.required_documents') as $required) {
            foreach ($required as $docType) {
                $label = DocumentTypes::label($docType);

                $this->assertNotSame($docType, $label, "«{$docType}» se está mostrando crudo");
                $this->assertStringNotContainsString('_', $label);
                $this->assertNotSame('', trim($label));
            }
        }
    }

    public function test_un_key_desconocido_se_humaniza_en_vez_de_mostrarse_crudo(): void
    {
        $this->assertSame('Identificacion Del Solicitante', DocumentTypes::label('identificacion_del_solicitante'));
    }

    public function test_sin_tipo_devuelve_sin_clasificar_y_no_una_key_vacia(): void
    {
        $this->assertSame('Sin clasificar', DocumentTypes::label(null));
        $this->assertSame('Sin clasificar', DocumentTypes::label(''));
        $this->assertSame('Sin clasificar', DocumentTypes::label('   '));
    }

    public function test_labels_no_repite_titulos(): void
    {
        // `ine` y `identificacion` apuntan al mismo título si alguien los
        // agrega juntos: en la lista de faltantes aparecería duplicado.
        $this->assertSame(
            ['Solicitud', 'Cédula de identidad'],
            DocumentTypes::labels(['solicitud', 'ine', 'solicitud']),
        );
    }

    public function test_el_titulo_sale_de_la_regla_y_no_de_un_mapa_hardcodeado(): void
    {
        // `doc_type_rules.label` es lo que el admin edita en «Reglas por tipo».
        // Si el título se hardcodeara, cambiar el label desde el admin no
        // cambiaría nada de lo que ve el usuario — y dos pantallas empezarían
        // a discrepar.
        DocTypeRule::query()->create([
            'name'             => 'ine',
            'label'            => 'INE / Cédula de identidad',
            'required_keywords' => ['cédula'],
            'active'           => true,
        ]);

        DocumentTypes::flushCache();

        $this->assertSame('INE / Cédula de identidad', DocumentTypes::label('ine'));
    }

    public function test_una_regla_sin_label_no_rompe_nada(): void
    {
        DocTypeRule::query()->create([
            'name'   => 'recibo',
            'label'  => null,
            'active' => true,
        ]);

        DocumentTypes::flushCache();

        // Sin título en la regla se cae a la humanización de la key, que es lo
        // que sea, pero no la key cruda.
        $this->assertSame('Recibo', DocumentTypes::label('recibo'));
    }
}
<?php

namespace Tests\Unit;

use App\Support\DocumentTypes;
use Tests\TestCase;

/**
 * Los keys de `required_documents` son para comparar; lo que se muestra es un
 * título. Este test fija las dos mitades: que los keys conocidos se traduzcan,
 * y que uno desconocido NO se muestre crudo.
 */
class DocumentTypesTest extends TestCase
{
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
            ['Solicitud', 'Identificación (INE)'],
            DocumentTypes::labels(['solicitud', 'ine', 'solicitud']),
        );
    }
}
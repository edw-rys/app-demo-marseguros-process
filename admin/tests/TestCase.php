<?php

namespace Tests;

use App\Support\DocumentTypes;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // `DocumentTypes` cachea los labels de `doc_type_rules` por proceso, y
        // entre tests eso filtra reglas del test anterior a un `RefreshDatabase`.
        DocumentTypes::flushCache();
    }
}
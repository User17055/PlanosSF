<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Storage;

abstract class TestCase extends BaseTestCase
{
    /**
     * Documentos vão para um disco temporário (nunca para o PLANOS_DOCUMENTS_ROOT real) e as
     * telas renderizam sem depender do build do Vite.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('planos.documents_disk'));
        $this->withoutVite();
    }
}

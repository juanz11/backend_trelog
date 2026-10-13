<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Los alias de middleware (`gateway.auth`, `sso.role`, `gateway.user`) se
        // vuelcan al router cuando se resuelve el kernel HTTP, y el TestCase de
        // Laravel solo resuelve el de consola. Los tests estructurales que leen la
        // cadena de middleware de cada ruta ANTES de hacer una peticion veian los
        // alias sin resolver. Hasta el Lote 9 lo tapaba un efecto colateral: el
        // provider de Sanctum resolvia el kernel HTTP al arrancar. Sanctum se fue;
        // esto lo hace explicito.
        $this->app->make(\Illuminate\Contracts\Http\Kernel::class);
    }
}

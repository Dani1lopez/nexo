<?php

namespace Tests\Feature;

use Tests\TestCase;

class HomeTest extends TestCase
{
    public function test_home_identifies_the_spanish_fictional_demo(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/html; charset=utf-8')
            ->assertSee('<html lang="es">', false)
            ->assertSee('<title>Nexo — Tesorería para asociaciones</title>', false)
            ->assertSee('Datos ficticios')
            ->assertSee('No utilices datos personales ni dinero real.')
            ->assertSee('La gestión de socios, cuotas y conciliación todavía no está disponible.')
            ->assertDontSee('<form', false)
            ->assertHeaderMissing('Set-Cookie');
    }
}

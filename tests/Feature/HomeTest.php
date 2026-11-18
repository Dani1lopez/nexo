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
            ->assertSee('<html lang="es"', false)
            ->assertSee('<title>Nexo — Tesorería para asociaciones</title>', false)
            ->assertSee('Datos ficticios')
            ->assertSee('No utilices datos personales ni dinero real.')
            ->assertDontSee('<form', false)
            ->assertHeaderMissing('Set-Cookie');
    }

    /** Issue #40: the home page names what the demo already does and keeps its limits explicit. */
    public function test_home_describes_the_features_that_exist_today(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Conciliar abonos con cuotas y repartir un abono entre varias cuotas.')
            ->assertSee('Qué incluye la demo')
            ->assertSee('conciliación manual con reparto de un abono entre varias cuotas y un resumen de saldos con la deuda por ejercicio')
            ->assertSee('No hay cobros reales ni conexión bancaria')
            ->assertSee('Esta demo no garantiza cumplimiento fiscal, contable o legal.')
            ->assertSee('Datos ficticios');
    }

    /** Issue #40: no placeholder may come back and announce as pending something already delivered. */
    public function test_home_does_not_announce_delivered_features_as_pending(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertDontSee('Próxima parada')
            ->assertDontSee('La gestión de socios, cuotas y conciliación todavía no está disponible.')
            ->assertDontSee('todavía no está disponible')
            ->assertDontSee('Primera entrega')
            ->assertDontSee('base de la aplicación');
    }
}

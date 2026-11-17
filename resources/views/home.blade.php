@extends('layouts.app')
@section('public', true)
@section('full_title', 'Nexo — Tesorería para asociaciones')
@section('content')
    <div class="field">
        <header class="hero">
            <h1>Tesorería para asociaciones</h1>
            <p class="lead">Las cuentas de la asociación, claras para quien venga después. Socios, cuotas y movimientos del banco en un solo sitio.</p>
            <a class="btn btn-pill" href="/login">Entrar a la demo</a>
            <p class="notice">Demo en construcción · Datos ficticios</p>
        </header>

        <div class="window" role="img" aria-label="Ejemplo ficticio de la aplicación: lista de movimientos bancarios con sus importes">
            <div class="window-bar">
                <span class="window-title">Movimientos bancarios</span>
                <span class="badge badge-partial">Ejemplo con datos ficticios</span>
            </div>
            <div class="sample sample-head"><span>Fecha</span><span>Concepto</span><span>Importe</span></div>
            <div class="sample"><small>12/01/2026</small><strong>Cuota Ana</strong><b class="text-ok">10,00 €</b></div>
            <div class="sample"><small>15/01/2026</small><strong>Recibo luz enero</strong><b class="text-danger">-84,50 €</b></div>
            <div class="sample"><small>10/03/2025</small><strong>Ingreso Luis Gómez</strong><b class="text-ok">15,00 €</b></div>
        </div>
    </div>

    <ol class="map" aria-label="Recorrido del dinero en una asociación">
        <li class="stop-socios">
            <strong>Socios</strong>
            <span>Quién forma la asociación.</span>
        </li>
        <li class="stop-cuotas">
            <strong>Cuotas</strong>
            <span>Lo que debe y lo que ha pagado cada socio.</span>
        </li>
        <li class="stop-movimientos">
            <strong>Movimientos</strong>
            <span>Lo que dice el banco, importado desde un archivo.</span>
        </li>
        <li class="stop-next">
            <strong>Conciliación</strong>
            <span>Conciliar abonos con cuotas y repartir un abono entre varias cuotas.</span>
        </li>
    </ol>

    <div class="grid gap-x-12 sm:grid-cols-2">
        <section aria-labelledby="purpose">
            <h2 id="purpose">Cuentas claras para el siguiente equipo</h2>
            <p>Cuotas, movimientos y conciliación para una asociación cultural ficticia.</p>
            <p>No utilices datos personales ni dinero real.</p>
        </section>

        <section aria-labelledby="status">
            <h2 id="status">Qué incluye la demo</h2>
            <p>Acceso con dos roles (tesorería y junta), socios, cuota anual con exenciones y pagos, importación de extractos en CSV, conciliación manual con reparto de un abono entre varias cuotas y un resumen de saldos con la deuda por ejercicio.</p>
            <p>No hay cobros reales ni conexión bancaria: el extracto se importa desde un archivo.</p>
            <p>Esta demo no garantiza cumplimiento fiscal, contable o legal.</p>
        </section>
    </div>
@endsection

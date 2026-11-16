@extends('layouts.app')
@section('public', true)
@section('full_title', 'Nexo — Tesorería para asociaciones')
@section('content')
    <header class="hero">
        <h1>Tesorería para asociaciones</h1>
        <p class="lead">Las cuentas de la asociación, claras para quien venga después. Socios, cuotas y movimientos del banco en un solo sitio.</p>
        <a class="btn" href="/login">Entrar a la demo</a>
        <p class="notice">Demo en construcción · Datos ficticios</p>
    </header>

    <div class="board mt-10" role="img" aria-label="Ejemplo ficticio de movimientos bancarios">
        <p class="sample-caption">Así se ven los movimientos importados del banco. Ejemplo con datos ficticios.</p>
        <div class="sample"><strong>Cuota Ana</strong><small>12/01/2026</small><b class="text-ok">10,00 €</b></div>
        <div class="sample"><strong>Recibo luz enero</strong><small>15/01/2026</small><b class="text-danger">-84,50 €</b></div>
        <div class="sample"><strong>Ingreso Luis Gómez</strong><small>10/03/2025</small><b class="text-ok">15,00 €</b></div>
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
            <span>Casar cada movimiento con su cuota. Próxima parada.</span>
        </li>
    </ol>

    <div class="grid gap-x-12 sm:grid-cols-2">
        <section aria-labelledby="purpose">
            <h2 id="purpose">Cuentas claras para el siguiente equipo</h2>
            <p>Cuotas, movimientos y conciliación para una asociación cultural ficticia.</p>
            <p>No utilices datos personales ni dinero real.</p>
        </section>

        <section aria-labelledby="status">
            <h2 id="status">Primera entrega: base de la aplicación</h2>
            <p>La gestión de socios, cuotas y conciliación todavía no está disponible.</p>
            <p>Esta demo no garantiza cumplimiento fiscal, contable o legal.</p>
        </section>
    </div>
@endsection

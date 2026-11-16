@extends('layouts.app')
@section('public', true)
@section('full_title', 'Nexo — Tesorería para asociaciones')
@section('content')
    <header>
        <p class="notice">Demo en construcción · Datos ficticios</p>
        <h1 class="text-8xl">Nexo</h1>
        <p class="font-display text-4xl/none font-semibold">Tesorería para asociaciones</p>
    </header>

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

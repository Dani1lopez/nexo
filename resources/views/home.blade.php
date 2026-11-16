@extends('layouts.app')
@section('public', true)
@section('full_title', 'Nexo — Tesorería para asociaciones')
@section('content')
    <header>
        <p class="notice">Demo en construcción · Datos ficticios</p>
        <h1 class="text-4xl">Nexo</h1>
        <p class="text-lg text-muted">Tesorería para asociaciones</p>
    </header>

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
@endsection

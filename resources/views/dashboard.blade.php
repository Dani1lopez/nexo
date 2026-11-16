@extends('layouts.app')
@section('title', 'Panel')
@section('content')
    <h1>Tu acceso</h1>
    <p>Sesión iniciada como <strong>{{ auth()->user()->name }}</strong>.</p>
    <dl>
        <dt>Correo</dt><dd>{{ auth()->user()->email }}</dd>
        <dt>Rol</dt><dd>{{ auth()->user()->role === 'treasury' ? 'Tesorería' : 'Junta' }}</dd>
    </dl>
    <p class="alert-info alert">Este panel es de consulta. Las operaciones económicas todavía no están disponibles.</p>
    <div class="link-grid">
        <a class="link-card" href="/members">Ver socios</a>
        <a class="link-card" href="/fees">Cuotas anuales</a>
        <a class="link-card" href="/bank">Movimientos bancarios</a>
    </div>
@endsection

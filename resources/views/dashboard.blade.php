@extends('layouts.app')
@section('title', 'Panel')
@section('content')
    <h1>Tu acceso</h1>
    <p>Sesión iniciada como <strong>{{ auth()->user()->name }}</strong>.</p>
    <dl>
        <dt>Correo</dt><dd>{{ auth()->user()->email }}</dd>
        <dt>Rol</dt><dd>{{ auth()->user()->role === 'treasury' ? 'Tesorería' : 'Junta' }}</dd>
    </dl>
    <p class="alert alert-info">Este panel es de consulta. Las operaciones económicas todavía no están disponibles.</p>
    <div class="link-grid">
        <a class="link-card" href="/members"><strong>Ver socios</strong><span>Altas, bajas y datos de contacto.</span></a>
        <a class="link-card" href="/fees"><strong>Cuotas anuales</strong><span>Quién ha pagado y quién debe.</span></a>
        <a class="link-card" href="/bank"><strong>Movimientos bancarios</strong><span>El extracto del banco, importado en CSV.</span></a>
    </div>
@endsection

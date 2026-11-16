@extends('layouts.access')
@section('title', 'Panel')
@section('content')
    <h1>Tu acceso</h1>
    <p>Sesión iniciada como <strong>{{ auth()->user()->name }}</strong>.</p>
    <dl>
        <dt>Correo</dt><dd>{{ auth()->user()->email }}</dd>
        <dt>Rol</dt><dd>{{ auth()->user()->role === 'treasury' ? 'Tesorería' : 'Junta' }}</dd>
    </dl>
    <p>Este panel es de consulta. Las operaciones económicas todavía no están disponibles.</p>
    <p><a href="/members">Ver socios</a></p>
    <form method="post" action="/logout">@csrf<button type="submit">Cerrar sesión</button></form>
@endsection

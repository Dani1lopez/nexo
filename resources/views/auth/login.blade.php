@extends('layouts.app')
@section('title', 'Acceso')
@section('narrow', true)
@section('content')
    <h1>Acceso individual</h1>
    <p>Entra con tu cuenta ficticia de tesorería o junta.</p>
    @if ($errors->any())
        <div class="alert alert-error" role="alert">{{ $errors->first() }}</div>
    @endif
    <form method="post" action="/login">
        @csrf
        <label for="email">Correo electrónico</label>
        <input id="email" name="email" type="email" autocomplete="username" value="{{ old('email') }}" required>
        <label for="password">Contraseña</label>
        <input id="password" name="password" type="password" autocomplete="current-password" required>
        <button type="submit" class="w-full">Entrar</button>
    </form>

    {{-- Issue #74: the explicit demo entry. It needs no account and marks only this session, so the
         real credentials and the real guards of the treasury stay exactly where they are. --}}
    <section aria-labelledby="probar-sin-cuenta">
        <h2 id="probar-sin-cuenta" class="mt-0">Probar sin cuenta</h2>
        <p>Recorre las pantallas reales con los datos de ejemplo: no creas ninguna cuenta y no toca la tesorería real ni los datos de otros visitantes.</p>
        <form method="post" action="/demo/entrar">
            @csrf
            <button type="submit" class="btn-secondary">Probar sin cuenta</button>
        </form>
    </section>
@endsection

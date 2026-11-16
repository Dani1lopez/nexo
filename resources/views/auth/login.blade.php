@extends('layouts.access')
@section('title', 'Acceso')
@section('content')
    <h1>Acceso individual</h1>
    <p>Entra con tu cuenta ficticia de tesorería o junta.</p>
    @if ($errors->any())
        <div class="error" role="alert">{{ $errors->first() }}</div>
    @endif
    <form method="post" action="/login">
        @csrf
        <label for="email">Correo electrónico</label>
        <input id="email" name="email" type="email" autocomplete="username" value="{{ old('email') }}" required>
        <label for="password">Contraseña</label>
        <input id="password" name="password" type="password" autocomplete="current-password" required>
        <button type="submit">Entrar</button>
    </form>
@endsection

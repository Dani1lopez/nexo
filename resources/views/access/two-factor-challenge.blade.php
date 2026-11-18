@extends('layouts.app')
@section('title', 'Verificación en dos pasos')
@section('narrow', true)
@section('content')
    <h1>Verificación en dos pasos</h1>
    <p>Introduce el código de 6 dígitos de tu aplicación de autenticación.</p>
    @if ($errors->any())
        <div class="alert alert-error" role="alert">{{ $errors->first() }}</div>
    @endif
    <form method="post" action="/login/segundo-factor">
        @csrf
        <label for="code">Código de 6 dígitos</label>
        <input id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required autofocus>
        <button type="submit" class="w-full">Verificar</button>
    </form>
    <p><a href="/login">Volver al acceso</a></p>
@endsection

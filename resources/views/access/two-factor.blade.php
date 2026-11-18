@extends('layouts.app')
@section('title', 'Verificación en dos pasos')
@section('narrow', true)
@section('content')
    <nav aria-label="Navegación" class="back-link"><a href="/dashboard">Volver al panel</a></nav>
    <h1>Verificación en dos pasos</h1>
    <p>Protege tu cuenta con un código temporal de una aplicación de autenticación.</p>

    @if ($errors->any())
        <div class="alert alert-error" role="alert">{{ $errors->first() }}</div>
    @endif

    @if ($user->hasConfirmedTwoFactor())
        <p class="notice">La verificación en dos pasos está activada.</p>
        <p>Para desactivarla, introduce un código válido de tu aplicación de autenticación.</p>
        <form method="post" action="/access/two-factor/disable">
            @csrf
            <label for="disable-code">Código actual de 6 dígitos</label>
            <input id="disable-code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required>
            <button type="submit" class="btn-danger">Desactivar la verificación en dos pasos</button>
        </form>
    @elseif ($secret !== null)
        <p class="notice">La verificación en dos pasos está pendiente de confirmación.</p>
        <p>Añade este secreto a tu aplicación de autenticación:</p>
        <p><code>{{ $secret }}</code></p>
        <p>O copia esta dirección en la aplicación:</p>
        <p><code>{{ $uri }}</code></p>
        <form method="post" action="/access/two-factor/confirm">
            @csrf
            <label for="confirm-code">Código de 6 dígitos</label>
            <input id="confirm-code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required>
            <button type="submit" class="btn">Confirmar y activar</button>
        </form>
    @else
        <p class="notice">La verificación en dos pasos no está activada.</p>
        <form method="post" action="/access/two-factor">
            @csrf
            <button type="submit" class="btn">Activar la verificación en dos pasos</button>
        </form>
    @endif
@endsection

@extends('layouts.app')
@php($editing = $member->exists)
@section('title', $editing ? 'Editar socio' : 'Nuevo socio')
@section('narrow', true)
@section('content')
    <nav aria-label="Navegación" class="back-link"><a href="/members">Volver a socios</a></nav>
    <h1>{{ $editing ? 'Editar socio' : 'Nuevo socio' }}</h1>
    <p class="notice">Usa solo datos ficticios.</p>
    @if ($errors->any())
        <div class="alert alert-error" role="alert">
            <ul>
                @foreach ($errors->all() as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        </div>
    @endif
    <form method="post" action="{{ $editing ? '/members/'.$member->id : '/members' }}">
        @csrf
        @if ($editing) @method('PUT') @endif
        <label for="name">Nombre completo</label>
        <input id="name" name="name" type="text" maxlength="255" autocomplete="off" value="{{ old('name', $member->name) }}" required>
        <label for="email">Correo ficticio (.test)</label>
        <input id="email" name="email" type="email" autocomplete="off" value="{{ old('email', $member->email) }}" required>
        <label for="joined_on">Fecha de alta</label>
        <input id="joined_on" name="joined_on" type="date" value="{{ old('joined_on', $member->joined_on?->toDateString()) }}" required>
        <button type="submit">{{ $editing ? 'Guardar cambios' : 'Crear socio' }}</button>
    </form>
@endsection

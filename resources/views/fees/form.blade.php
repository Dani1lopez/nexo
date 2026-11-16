@extends('layouts.access')
@php($editing = $feeYear->exists)
@section('title', $editing ? 'Cambiar importe' : 'Fijar cuota anual')
@section('content')
    <nav aria-label="Navegación"><a href="{{ $editing ? '/fees/'.$feeYear->id : '/fees' }}">Volver</a></nav>
    <h1>{{ $editing ? 'Cambiar el importe de '.$feeYear->year : 'Fijar cuota de un año' }}</h1>
    <p>Usa solo importes ficticios. Al fijar un año se asigna la cuota a los socios activos con alta hasta el fin de ese año.</p>
    @if ($errors->any())
        <div class="error" role="alert">
            <ul>
                @foreach ($errors->all() as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        </div>
    @endif
    <form method="post" action="{{ $editing ? '/fees/'.$feeYear->id : '/fees' }}">
        @csrf
        @if ($editing) @method('PUT') @endif
        @unless ($editing)
            <label for="year">Año</label>
            <input id="year" name="year" type="number" min="2000" max="2100" value="{{ old('year', now()->year) }}" required>
        @endunless
        <label for="amount">Importe en euros</label>
        <input id="amount" name="amount" type="text" inputmode="decimal" autocomplete="off" value="{{ old('amount', $editing ? \App\Modules\Fees\Support\Money::input($feeYear->amount_cents) : '') }}" required>
        <button type="submit">{{ $editing ? 'Guardar importe' : 'Fijar cuota' }}</button>
    </form>
@endsection

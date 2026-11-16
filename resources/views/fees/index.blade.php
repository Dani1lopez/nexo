@extends('layouts.access')
@section('title', 'Cuotas anuales')
@section('content')
    <nav aria-label="Navegación"><a href="/dashboard">Volver al panel</a></nav>
    <h1>Cuotas anuales</h1>
    <p>Importes en euros, con datos exclusivamente ficticios.</p>
    @can('edit-treasury')
        <p><a href="/fees/create">Fijar cuota de un año</a></p>
    @endcan
    @forelse ($years as $feeYear)
        <article class="member">
            <h2><a href="/fees/{{ $feeYear->id }}">Cuota de {{ $feeYear->year }}</a></h2>
            <dl>
                <dt>Importe</dt><dd>{{ \App\Modules\Fees\Support\Money::format($feeYear->amount_cents) }}</dd>
                <dt>Cuotas asignadas</dt><dd>{{ $feeYear->dues_count }}</dd>
            </dl>
        </article>
    @empty
        <p>Todavía no se ha fijado ninguna cuota.</p>
    @endforelse
@endsection

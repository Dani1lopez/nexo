@extends('layouts.app')
@section('title', 'Cuotas anuales')
@section('content')
    <nav aria-label="Navegación" class="back-link"><a href="/dashboard">Volver al panel</a></nav>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h1 class="mb-0">Cuotas anuales</h1>
        @can('edit-treasury')
            <a href="/fees/create" class="btn mt-0">Fijar cuota de un año</a>
        @endcan
    </div>
    <p class="notice">Importes en euros, con datos exclusivamente ficticios.</p>
    @forelse ($years as $feeYear)
        <article class="entry">
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

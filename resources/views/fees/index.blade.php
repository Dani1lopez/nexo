@extends('layouts.app')
@section('title', 'Cuotas anuales')
@section('content')
    {{-- A demo session walks this real screen, so it hides the controls the demo bounces. --}}
    @php($demoSession = session(\App\Modules\Demo\Support\DemoSession::MARK) === true)
    <nav aria-label="Navegación" class="back-link"><a href="/dashboard">Volver al panel</a></nav>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h1 class="mb-0">Cuotas anuales</h1>
        @if (! $demoSession)
            @can('edit-treasury')
                <a href="/fees/create" class="btn mt-0">Fijar cuota de un año</a>
            @endcan
        @endif
    </div>
    <p class="notice">Importes en euros, con datos exclusivamente ficticios.</p>
    @if ($years->isNotEmpty())
        <div class="board">
            @foreach ($years as $feeYear)
                <article class="entry">
                    <h2><a href="/fees/{{ $feeYear->id }}">Cuota de {{ $feeYear->year }}</a></h2>
                    <dl>
                        <dt>Importe</dt><dd>{{ \App\Modules\Fees\Support\Money::format($feeYear->amount_cents) }}</dd>
                        <dt>Cuotas asignadas</dt><dd>{{ $feeYear->dues_count }}</dd>
                    </dl>
                </article>
            @endforeach
        </div>
    @else
        <div class="empty">
            <p>Todavía no se ha fijado ninguna cuota.</p>
            @if (! $demoSession)
                @can('edit-treasury')
                    <a href="/fees/create" class="btn btn-secondary btn-sm">Fijar la primera cuota</a>
                @endcan
            @endif
        </div>
    @endif
@endsection

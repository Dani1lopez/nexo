@extends('layouts.app')
@section('title', 'Panel')
@section('content')
    @php
        $pendingDues = $overview['pendingDues'];
        $paidShare = $overview['payableCents'] > 0
            ? (int) round($overview['paidCents'] / $overview['payableCents'] * 100)
            : 0;
    @endphp

    <header class="page-head">
        <h1>Panel{{ $overview['year'] ? ' · ejercicio '.$overview['year'] : '' }}</h1>
        <p class="meta">
            @if ($overview['lastImportAt'])
                Extracto actualizado el {{ $overview['lastImportAt']->format('d/m/Y') }}
            @else
                Sin extracto importado todavía
            @endif
        </p>
    </header>

    @if ($overview['year'])
        <section class="kpis" aria-label="Cifras del ejercicio {{ $overview['year'] }}">
            <div>
                <span class="k">Pendiente de cobro</span>
                <span class="n hero">{{ \App\Modules\Fees\Support\Money::format($overview['pendingCents']) }}</span>
                <span class="sub{{ $pendingDues > 0 ? ' warn' : '' }}">
                    {{ $pendingDues }} {{ $pendingDues === 1 ? 'cuota pendiente' : 'cuotas pendientes' }}
                </span>
            </div>
            <div>
                <span class="k">Cobrado</span>
                <span class="n">{{ \App\Modules\Fees\Support\Money::format($overview['paidCents']) }}</span>
                <span class="sub">de {{ \App\Modules\Fees\Support\Money::format($overview['payableCents']) }} a cobrar</span>
                <span class="progress" role="img" aria-label="Cobrado el {{ $paidShare }} % de lo previsto">
                    <i style="width: {{ $paidShare }}%"></i>
                </span>
            </div>
            <div>
                <span class="k">Socios activos</span>
                <span class="n">{{ $overview['activeMembers'] }}</span>
                <span class="sub">{{ $overview['activeMembers'] === 1 ? 'persona con alta vigente' : 'personas con alta vigente' }}</span>
            </div>
            <div>
                <span class="k">Movimientos</span>
                <span class="n">{{ $overview['movements'] }}</span>
                <span class="sub">en el extracto importado</span>
            </div>
        </section>
    @else
        <div class="empty">
            <p>Todavía no hay cuotas fijadas, así que no hay cifras de cobro que mostrar. Hay {{ $overview['activeMembers'] }} {{ $overview['activeMembers'] === 1 ? 'socio activo' : 'socios activos' }}.</p>
            <a class="btn btn-sm" href="/fees">Fijar la cuota del año</a>
        </div>
    @endif

    <p class="section-label">Siguientes pasos</p>
    <nav class="next" aria-label="Siguientes pasos">
        <a href="/members">
            <b>Ver socios</b>
            <span>Altas, bajas y datos de contacto.</span>
            <span class="arrow" aria-hidden="true">→</span>
        </a>
        <a href="/fees">
            <b>Cuotas anuales</b>
            <span>Quién ha pagado y quién debe.</span>
            <span class="arrow" aria-hidden="true">→</span>
        </a>
        <a href="/bank">
            <b>Movimientos bancarios</b>
            <span>El extracto del banco, importado en CSV.</span>
            <span class="arrow" aria-hidden="true">→</span>
        </a>
    </nav>

    {{-- Issue #74: the first step of the guide, right under the block of the real link it names. --}}
    <x-guide-notice :step="\App\Modules\Demo\Support\GuideSteps::PANEL" />

    <p class="section-label">Tu acceso</p>
    <p class="stale">
        Sesión iniciada como <strong>{{ auth()->user()->name }}</strong> ·
        {{ auth()->user()->role === 'treasury' ? 'Tesorería, con edición' : 'Junta, solo consulta' }} ·
        {{ auth()->user()->email }}
    </p>

    <p class="alert alert-info">Las cifras de cobro son del último ejercicio fijado. El saldo del banco, la deuda por ejercicio y los abonos sin conciliar están en <a href="/saldos">Saldos</a>.</p>
@endsection

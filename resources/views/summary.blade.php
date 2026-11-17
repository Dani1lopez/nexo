@extends('layouts.app')
@section('title', 'Saldos')
@section('content')
    @php
        $money = fn (int $cents) => \App\Modules\Fees\Support\Money::format($cents);
        $abonos = $balances['unreconciledCount'];
        $movimientos = $balances['movements'];
        $pendientes = $balances['totalPendingDues'];
    @endphp

    <nav aria-label="Navegación" class="back-link"><a href="/dashboard">Volver al panel</a></nav>
    <h1>Saldos</h1>
    <p class="notice">Importes en euros, con datos exclusivamente ficticios.</p>

    @if ($movimientos > 0)
        <section class="kpis" aria-label="Banco">
            <div>
                <span class="k">Saldo en el banco</span>
                <span class="n hero">{{ $money($balances['balanceCents']) }}</span>
                <span class="sub">según el extracto importado</span>
            </div>
            <div>
                <span class="k">Sin conciliar</span>
                <span class="n">{{ $money($balances['unreconciledCents']) }}</span>
                <span class="sub{{ $abonos > 0 ? ' warn' : '' }}">
                    {{ $abonos }} {{ $abonos === 1 ? 'abono sin conciliar' : 'abonos sin conciliar' }}
                </span>
            </div>
            <div>
                <span class="k">Movimientos</span>
                <span class="n">{{ $movimientos }}</span>
                <span class="sub">{{ $movimientos === 1 ? 'movimiento' : 'movimientos' }} en el extracto importado</span>
            </div>
        </section>
        <p class="notice">
            El saldo es el del extracto importado: los abonos suman y los cargos restan.
            La caja todavía no está incluida.
            @if ($balances['lastImportAt'])
                Última importación el {{ $balances['lastImportAt']->format('d/m/Y') }}.
            @endif
            Los abonos sin conciliar se resuelven en <a href="/bank">Movimientos</a>.
        </p>
    @else
        <div class="empty">
            <p>Todavía no se ha importado ningún extracto, así que no hay saldo que mostrar.</p>
            <a class="btn btn-secondary btn-sm" href="/bank">Ir a movimientos</a>
        </div>
    @endif

    <p class="section-label">Cuotas pendientes por ejercicio</p>
    @if ($balances['years'] !== [])
        <div class="board">
            @foreach ($balances['years'] as $row)
                @php($dues = $row['pendingDues'])
                <article class="entry">
                    <h2><a href="/fees/{{ $row['id'] }}">Cuota de {{ $row['year'] }}</a></h2>
                    <dl>
                        <dt>A cobrar</dt><dd>{{ $money($row['payableCents']) }}</dd>
                        <dt>Cobrado</dt><dd>{{ $money($row['paidCents']) }}</dd>
                        <dt>Por cobrar</dt><dd>{{ $money($row['pendingCents']) }}</dd>
                        <dt>Pendientes</dt><dd>{{ $dues }} {{ $dues === 1 ? 'cuota pendiente' : 'cuotas pendientes' }}</dd>
                    </dl>
                </article>
            @endforeach
        </div>
        <div class="tally" role="group" aria-label="Deuda total">
            <p><span>Total pendiente</span><strong>{{ $money($balances['totalPendingCents']) }}</strong></p>
            <p>
                <span>Cuotas pendientes</span>
                <strong>{{ $pendientes }} {{ $pendientes === 1 ? 'cuota pendiente' : 'cuotas pendientes' }}</strong>
            </p>
        </div>
        <p class="notice">La deuda es la de todos los ejercicios fijados. Una cuota exenta no se debe y un pago anulado no cuenta como cobrado.</p>
    @else
        <div class="empty">
            <p>Todavía no se ha fijado ninguna cuota, así que no hay deuda que mostrar.</p>
            <a class="btn btn-secondary btn-sm" href="/fees">Ir a cuotas anuales</a>
        </div>
    @endif
@endsection

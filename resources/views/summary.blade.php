@extends('layouts.app')
@section('title', 'Saldos')
@section('content')
    @php
        // A demo session walks this real screen, so it hides the controls the demo bounces.
        $demoSession = session(\App\Modules\Demo\Support\DemoSession::MARK) === true;
        $money = fn (int $cents) => \App\Modules\Fees\Support\Money::format($cents);
        $movimientos = $balances['movements'];
        $pendientes = $balances['totalPendingDues'];
        $pagosEfectivo = $balances['cashPayments'];
    @endphp

    <nav aria-label="Navegación" class="back-link">
        <a href="/dashboard">Volver al panel</a>
    </nav>
    <h1>Saldos</h1>
    <p class="notice">Importes en euros, con datos exclusivamente ficticios.</p>

    @if ($movimientos > 0)
        <x-bank-kpis :balances="$balances" />
        <x-guide-notice :step="\App\Modules\Demo\Support\GuideSteps::SALDOS" />
        <p class="notice">
            El saldo es el del extracto importado: los abonos suman y los cargos restan.
            Los pagos en efectivo no cambian este saldo.
            @if ($balances['lastImportAt'])
                Última importación el {{ $balances['lastImportAt']->format('d/m/Y') }}.
            @endif
            Los abonos sin conciliar se resuelven en <a href="/bank">Movimientos</a>.
            Un abono puede estar conciliado en parte: aquí cuenta solo lo que falta.
            Un abono clasificado como otro ingreso ya tiene su destino y no cuenta como pendiente.
        </p>
    @else
        <div class="empty">
            <p>Todavía no se ha importado ningún extracto, así que no hay saldo que mostrar.</p>
            <a class="btn btn-secondary btn-sm" href="/bank">Ir a movimientos</a>
        </div>
    @endif

    <p class="section-label">Caja</p>
    <section class="kpis" aria-label="Caja">
        <div>
            <span class="k">Saldo en caja</span>
            <span class="n hero">{{ $money($balances['cashCents']) }}</span>
            <span class="sub">base inicial más cuotas en efectivo, menos los gastos confirmados</span>
        </div>
        <div>
            <span class="k">Base inicial</span>
            <span class="n">{{ $balances['openingCents'] === null ? 'Sin fijar' : $money($balances['openingCents']) }}</span>
            <span class="sub">{{ $balances['openingCents'] === null ? 'Sin saldo inicial fijado' : 'se suma una sola vez' }}</span>
        </div>
        <div>
            <span class="k">Cuotas en efectivo</span>
            <span class="n">{{ $money($balances['cashFeeCents']) }}</span>
            <span class="sub">{{ $pagosEfectivo }} {{ $pagosEfectivo === 1 ? 'pago de cuota en efectivo' : 'pagos de cuotas en efectivo' }}</span>
        </div>
        @php($traspasos = $balances['transferCount'])
        @if ($traspasos > 0)
            <div>
                <span class="k">Traspasos</span>
                <span class="n">{{ $money($balances['transferCents']) }}</span>
                <span class="sub">{{ $traspasos }} {{ $traspasos === 1 ? 'traspaso entre banco y caja' : 'traspasos entre banco y caja' }}. Ni ingreso ni gasto.</span>
            </div>
        @endif
        @php($gastos = $balances['expenseCount'])
        <div>
            <span class="k">Gastos confirmados</span>
            <span class="n">{{ $money($balances['expenseCents']) }}</span>
            <span class="sub">{{ $gastos }} {{ $gastos === 1 ? 'gasto confirmado' : 'gastos confirmados' }}. Los borradores aún no descuentan.</span>
        </div>
    </section>
    <p class="notice">
        La caja suma los pagos de cuotas en efectivo vigentes más la base inicial y resta los gastos confirmados; los traspasos con el banco la mueven en la dirección del dinero.
        Un pago o un gasto anulado no cuenta y conserva su historial.
        La base inicial se fija una sola vez en
        <a href="/caja">Caja</a>
        y no incluye los pagos ya registrados:
        si los incluyera, contarían dos veces.
        El banco y la caja se muestran por separado: un pago en efectivo no cambia el saldo bancario.
    </p>

    <p class="section-label">Cuotas pendientes por ejercicio</p>
    @if ($balances['years'] !== [])
        <div class="board">
            @foreach ($balances['years'] as $row)
                @php($dues = $row['pendingDues'])
                <article class="entry">
                    <h2>
                        @if ($demoSession)
                            Cuota de {{ $row['year'] }}
                        @else
                            <a href="/fees/{{ $row['id'] }}">Cuota de {{ $row['year'] }}</a>
                        @endif
                    </h2>
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

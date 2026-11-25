@props(['balances'])

@php
    $money = fn (int $cents): string => \App\Modules\Fees\Support\Money::format($cents);
    $abonos = $balances['unreconciledCount'];
    $movimientos = $balances['movements'];
@endphp

{{-- The bank figures of /saldos, shared with the guided demo so both screens can never disagree. --}}
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
    @if ($balances['otherIncomeCount'] > 0)
        @php($ingresos = $balances['otherIncomeCount'])
        <div>
            <span class="k">Otros ingresos</span>
            <span class="n">{{ $money($balances['otherIncomeCents']) }}</span>
            <span class="sub">{{ $ingresos }} {{ $ingresos === 1 ? 'ingreso clasificado' : 'ingresos clasificados' }}. Ya incluidos en el saldo bancario.</span>
        </div>
    @endif
    @if ($balances['bankExpenseCount'] > 0)
        @php($gastosBanco = $balances['bankExpenseCount'])
        <div>
            <span class="k">Gastos bancarios</span>
            <span class="n">{{ $money($balances['bankExpenseCents']) }}</span>
            <span class="sub">{{ $gastosBanco }} {{ $gastosBanco === 1 ? 'cargo clasificado' : 'cargos clasificados' }}. Ya restados en el saldo bancario.</span>
        </div>
    @endif
    <div>
        <span class="k">Movimientos</span>
        <span class="n">{{ $movimientos }}</span>
        <span class="sub">{{ $movimientos === 1 ? 'movimiento' : 'movimientos' }} en el extracto importado</span>
    </div>
</section>

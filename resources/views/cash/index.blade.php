@extends('layouts.app')
@section('title', 'Caja')
@section('content')
    @php
        $money = fn (int $cents) => \App\Modules\Fees\Support\Money::format($cents);
        $pagosEfectivo = $cashPayments;
        $subPagos = $pagosEfectivo === 1 ? 'pago de cuota en efectivo' : 'pagos de cuotas en efectivo';
        $subCuotas = $pagosEfectivo === 1 ? 'pago de cuota en efectivo vigente' : 'pagos de cuotas en efectivo vigentes';
    @endphp

    <nav aria-label="Navegación" class="back-link"><a href="/saldos">Volver a saldos</a></nav>
    <h1>Caja</h1>
    <p class="notice">Importes en euros, con datos exclusivamente ficticios.</p>

    @if ($errors->any())
        <div class="alert alert-error" role="alert"><ul>@foreach ($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul></div>
    @endif

    <section class="kpis" aria-label="Resumen de caja">
        <div>
            <span class="k">Saldo en caja</span>
            <span class="n hero">{{ $money($cashCents) }}</span>
            <span class="sub">base inicial más cuotas en efectivo</span>
        </div>
        <div>
            <span class="k">Base inicial</span>
            <span class="n">{{ $openingCents === null ? 'Sin fijar' : $money($openingCents) }}</span>
            <span class="sub">
                @if ($openingCents === null)
                    Todavía no se ha fijado el saldo inicial
                @else
                    fijada una sola vez por {{ $opening->user->email }} el {{ $opening->fixedOn()->format('d/m/Y') }}
                @endif
            </span>
        </div>
        <div>
            <span class="k">Cuotas en efectivo</span>
            <span class="n">{{ $money($cashFeeCents) }}</span>
            <span class="sub">{{ $pagosEfectivo }} {{ $subCuotas }}</span>
        </div>
    </section>
    <p class="notice">
        La caja suma los pagos de cuotas en efectivo vigentes más la base inicial; un pago anulado no cuenta.
        El banco y la caja se muestran por separado y un pago en efectivo no cambia el saldo bancario.
    </p>

    <p class="section-label">Saldo inicial</p>
    @if ($opening === null)
        <p class="notice">La tesorería todavía no ha fijado el saldo inicial. Hasta entonces la caja solo suma los {{ $pagosEfectivo }} {{ $subPagos }}.</p>
        @can('edit-treasury')
            <form method="post" action="/caja/saldo-inicial" class="form-block">
                @csrf
                <label for="amount" class="mt-0">Saldo inicial en euros, sin contar los pagos de cuotas en efectivo</label>
                <input id="amount" name="amount" type="text" inputmode="decimal" autocomplete="off" value="{{ old('amount') }}" required>
                <p class="notice">
                    Escribe solo el dinero que ya había en la caja antes de usar Nexo. Los pagos de cuotas en efectivo que Nexo
                    ya registra se suman solos: si los incluyes en la base, contarían dos veces.
                </p>
                <p class="notice">
                    Se fija una sola vez. Reenviar el mismo importe no cambia nada y un importe distinto no lo sobrescribe.
                    Puedes fijarlo en cero si la caja estaba vacía: cero no es lo mismo que no haberlo fijado.
                </p>
                <button type="submit">Fijar saldo inicial</button>
            </form>
        @endcan
    @else
        <div class="empty">
            <p>
                Base inicial de {{ $money($openingCents) }}, fijada por {{ $opening->user->email }} el
                {{ $opening->fixedOn()->format('d/m/Y') }}. No se recalcula ni se sobrescribe.
            </p>
        </div>
    @endif
@endsection

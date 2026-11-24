@extends('layouts.app')
@section('title', 'Demostración guiada')
@section('public', true)
@section('content')
    @php
        $money = fn (int $cents): string => \App\Modules\Fees\Support\Money::format($cents);
        $visitedOn = \Illuminate\Support\Carbon::parse($scenario->occurredOn)->format('d/m/Y');
    @endphp

    <nav aria-label="Navegación" class="back-link"><a href="/">Volver a la portada</a></nav>
    <h1>Demostración guiada</h1>
    <p class="notice">
        Las operaciones de este ejemplo son reales, pero ocurren en una base de datos desechable que no guarda nada al terminar.
        No crea ninguna cuenta y no toca la tesorería real ni los datos de otros visitantes.
    </p>

    @if ($demoErrors->isNotEmpty())
        <div class="alert alert-error" role="alert"><ul>@foreach ($demoErrors->all() as $message)<li>{{ $message }}</li>@endforeach</ul></div>
    @endif

    <section aria-labelledby="paso-1">
        <p class="section-label">Paso 1 de 3</p>
        <h2 id="paso-1">Qué vas a probar</h2>
        <p>
            Una asociación ficticia tiene dos socios con una cuota de {{ $money($scenario->feeCents) }} cada uno
            en el ejercicio {{ $scenario->year }}. El banco ha ingresado un único abono de
            {{ $money($scenario->creditCents) }} con el concepto «{{ $scenario->concept }}» el {{ $visitedOn }}.
            Vas a repartirlo entre las dos cuotas y a comprobar qué queda en el banco.
        </p>
        <ul>
            @foreach ($scenario->members() as $member)
                <li>Cuota de {{ $money($scenario->feeCents) }} · {{ $member['name'] }} · {{ $member['email'] }}</li>
            @endforeach
        </ul>
        <dl>
            <dt>Abono importado</dt><dd>{{ $money($scenario->creditCents) }}</dd>
            <dt>Concepto</dt><dd>{{ $scenario->concept }}</dd>
            <dt>Fecha del abono</dt><dd>{{ $visitedOn }}</dd>
            <dt>Ejercicio</dt><dd>{{ $scenario->year }}</dd>
        </dl>
        <p>
            El extracto se genera en memoria y se importa con la misma operación que usa Movimientos.
            No subes ningún archivo y no se guarda ninguno.
        </p>
    </section>

    <section aria-labelledby="paso-2">
        <p class="section-label">Paso 2 de 3</p>
        <h2 id="paso-2">Concilia el abono</h2>
        @if ($result)
            <p class="alert alert-ok status" role="status">Reparto conciliado desde el extracto.</p>
            <ul>
                @foreach ($lines as $line)
                    <li>{{ $line['name'] }} · {{ $line['year'] }} · {{ $money($line['amountCents']) }}</li>
                @endforeach
            </ul>
            <p>El reparto se ha guardado con la misma acción que concilia un abono en Movimientos. Cada pago queda atribuido a su socio y a su ejercicio.</p>
        @else
            <p>
                El abono de {{ $money($scenario->creditCents) }} está pendiente de destino. Escribe lo que paga cada cuota;
                lo que no asignes seguirá pendiente.
            </p>
            <form method="post" action="/demo/conciliar" class="form-block">
                @csrf
                @foreach ($scenario->members() as $key => $member)
                    <label for="allocations-{{ $key }}">{{ $member['name'] }} · {{ $scenario->year }} · cuota de {{ $money($scenario->feeCents) }}</label>
                    <input id="allocations-{{ $key }}" name="allocations[{{ $key }}]" type="text" inputmode="decimal" autocomplete="off" value="{{ \App\Modules\Fees\Support\Money::input($scenario->feeCents) }}">
                @endforeach
                <p class="notice">La suma no puede superar lo que queda por conciliar y cada línea respeta lo pendiente de su cuota. Lo que no asignes seguirá pendiente.</p>
                <button type="submit">Conciliar 80 €</button>
            </form>
        @endif
    </section>

    @if ($result)
        <section aria-labelledby="paso-3">
            <p class="section-label">Paso 3 de 3</p>
            <h2 id="paso-3">Resultado</h2>
            <h3>Saldos</h3>
            <x-bank-kpis :balances="$balances" />
            <p class="notice">
                Los {{ $money($balances['unreconciledCents']) }} que siguen sin conciliar son el resto del abono, no deuda de cuotas.
                @if ($balances['totalPendingDues'] === 0)
                    Las cuotas de los dos socios quedan pagadas.
                @else
                    {{ $balances['totalPendingDues'] === 1 ? 'Queda 1 cuota pendiente' : 'Quedan '.$balances['totalPendingDues'].' cuotas pendientes' }}: este reparto no cubre las dos cuotas.
                @endif
            </p>

            <p class="section-label">Cuotas pendientes por ejercicio</p>
            @if ($balances['years'] !== [])
                <div class="board">
                    @foreach ($balances['years'] as $row)
                        @php($dues = $row['pendingDues'])
                        <article class="entry">
                            <h3>Cuota de {{ $row['year'] }}</h3>
                            <dl>
                                <dt>A cobrar</dt><dd>{{ $money($row['payableCents']) }}</dd>
                                <dt>Cobrado</dt><dd>{{ $money($row['paidCents']) }}</dd>
                                <dt>Por cobrar</dt><dd>{{ $money($row['pendingCents']) }}</dd>
                                <dt>Pendientes</dt><dd>{{ $dues }} {{ $dues === 1 ? 'cuota pendiente' : 'cuotas pendientes' }}</dd>
                            </dl>
                        </article>
                    @endforeach
                </div>
            @endif

            <p><a class="btn btn-secondary btn-sm" href="/demo">Volver a empezar</a></p>
        </section>
    @endif
@endsection

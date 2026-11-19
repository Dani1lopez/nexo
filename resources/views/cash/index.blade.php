@extends('layouts.app')
@section('title', 'Caja')
@section('content')
    @php
        $money = fn (int $cents) => \App\Modules\Fees\Support\Money::format($cents);
        $pagosEfectivo = $cashPayments;
        $subCuotas = $pagosEfectivo === 1 ? 'pago de cuota en efectivo vigente' : 'pagos de cuotas en efectivo vigentes';
        $gastos = $expenseCount;
        $subGastos = $gastos === 1 ? 'gasto confirmado' : 'gastos confirmados';
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
            <span class="sub">base inicial más cuotas en efectivo, menos los gastos confirmados</span>
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
        <div>
            <span class="k">Gastos confirmados</span>
            <span class="n">{{ $money($expenseCents) }}</span>
            <span class="sub">{{ $gastos }} {{ $subGastos }}. Los borradores aún no descuentan.</span>
        </div>
    </section>
    <p class="notice">
        La caja suma los pagos de cuotas en efectivo vigentes más la base inicial y resta los gastos confirmados.
        Un pago o un gasto anulado no cuenta y conserva su historial.
        El banco y la caja se muestran por separado: nada de esta página cambia el saldo bancario.
    </p>

    <p class="section-label">Saldo inicial</p>
    @if ($opening === null)
        <p class="notice">La tesorería todavía no ha fijado el saldo inicial. Hasta entonces la caja solo suma los pagos de cuotas en efectivo vigentes.</p>
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

    <p class="section-label">Gastos en efectivo</p>
    @can('edit-treasury')
        <form method="post" action="/caja/gastos" class="form-block">
            @csrf
            <input type="hidden" name="submission_key" value="{{ \Illuminate\Support\Str::uuid() }}">
            <label for="spent_on" class="mt-0">Fecha</label>
            <input id="spent_on" name="spent_on" type="date" value="{{ old('spent_on', now()->toDateString()) }}" required>
            <label for="expense-amount">Importe en euros</label>
            <input id="expense-amount" name="amount" type="text" inputmode="decimal" autocomplete="off" value="{{ old('amount') }}" required>
            <label for="category">Categoría</label>
            <select id="category" name="category" required>
                @foreach (\App\Modules\Cash\Models\CashExpense::CATEGORIES as $key => $label)
                    <option value="{{ $key }}" @selected(old('category') === $key)>{{ $label }}</option>
                @endforeach
            </select>
            <label for="concept">Concepto</label>
            <input id="concept" name="concept" type="text" maxlength="255" autocomplete="off" value="{{ old('concept') }}" required>
            <label class="check">
                <input type="checkbox" name="has_receipt" value="1" @checked(old('has_receipt'))>
                Tengo el justificante
            </label>
            <label for="reason">Motivo, obligatorio si no hay justificante</label>
            <input id="reason" name="reason" type="text" maxlength="500" autocomplete="off" value="{{ old('reason') }}">
            <p class="notice">El gasto se guarda en borrador: no descuenta de la caja hasta que lo confirmas.</p>
            <button type="submit">Guardar en borrador</button>
        </form>
    @endcan

    @if ($expenses->isEmpty())
        <div class="empty">
            <p>Todavía no hay gastos registrados en la caja.</p>
        </div>
    @else
        <table>
            <thead>
                <tr>
                    <th scope="col">Fecha</th>
                    <th scope="col">Categoría</th>
                    <th scope="col">Concepto</th>
                    <th scope="col">Importe</th>
                    <th scope="col">Estado</th>
                    @can('edit-treasury')<th scope="col"><span class="sr-only">Acciones</span></th>@endcan
                </tr>
            </thead>
            <tbody>
                @foreach ($expenses as $expense)
                    <tr>
                        <td>{{ $expense->spent_on->format('d/m/Y') }}</td>
                        <td>{{ $expense->categoryLabel() }}</td>
                        <td>{{ $expense->concept }}</td>
                        <td>{{ $money($expense->amount_cents) }}</td>
                        <td>
                            @if ($expense->isDraft())
                                Borrador
                            @elseif ($expense->isConfirmed())
                                Confirmado
                            @else
                                Anulado — {{ $expense->void_reason }}
                            @endif
                            @if ($expense->has_receipt)
                                · con justificante
                            @elseif ($expense->reason)
                                · sin justificante: {{ $expense->reason }}
                            @endif
                        </td>
                        @can('edit-treasury')
                            <td>
                                @if ($expense->isDraft())
                                    <a class="btn btn-secondary btn-sm" href="/caja/gastos/{{ $expense->id }}/edit">Editar</a>
                                    <form method="post" action="/caja/gastos/{{ $expense->id }}/confirm" class="inline-form">
                                        @csrf
                                        <button type="submit" class="btn-secondary btn-sm">Confirmar</button>
                                    </form>
                                @elseif ($expense->isConfirmed())
                                    <form method="post" action="/caja/gastos/{{ $expense->id }}/void" class="form-block">
                                        @csrf
                                        <label for="void-{{ $expense->id }}" class="mt-0">Motivo de la anulación del gasto de {{ $expense->spent_on->format('d/m/Y') }}</label>
                                        <input id="void-{{ $expense->id }}" name="reason" type="text" maxlength="500" required>
                                        <button type="submit" class="btn-danger btn-sm mt-3">Anular gasto</button>
                                    </form>
                                @endif
                            </td>
                        @endcan
                    </tr>
                @endforeach
            </tbody>
        </table>
        {{ $expenses->links() }}
    @endif
@endsection

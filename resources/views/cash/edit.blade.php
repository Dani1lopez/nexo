@extends('layouts.app')
@section('title', 'Editar gasto en borrador')
@section('content')
    @php($money = fn (int $cents) => \App\Modules\Fees\Support\Money::format($cents))
    <nav aria-label="Navegación" class="back-link"><a href="/caja">Volver a caja</a></nav>
    <h1>Editar gasto en borrador</h1>
    <p class="notice">Importes en euros, con datos exclusivamente ficticios.</p>

    @if ($errors->any())
        <div class="alert alert-error" role="alert">
            <ul>
                @foreach ($errors->all() as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="post" action="/caja/gastos/{{ $expense->id }}" class="form-block">
        @csrf
        @method('PUT')
        <label for="spent_on" class="mt-0">Fecha</label>
        <input id="spent_on" name="spent_on" type="date" value="{{ old('spent_on', $expense->spent_on->toDateString()) }}" required>
        <label for="expense-amount">Importe en euros (ahora {{ $money($expense->amount_cents) }})</label>
        <input id="expense-amount" name="amount" type="text" inputmode="decimal" autocomplete="off" value="{{ old('amount', number_format($expense->amount_cents / 100, 2, ',', '')) }}" required>
        <label for="category">Categoría</label>
        <select id="category" name="category" required>
            @foreach (\App\Modules\Cash\Models\CashExpense::CATEGORIES as $key => $label)
                <option value="{{ $key }}" @selected(old('category', $expense->category) === $key)>{{ $label }}</option>
            @endforeach
        </select>
        <label for="concept">Concepto</label>
        <input id="concept" name="concept" type="text" maxlength="255" autocomplete="off" value="{{ old('concept', $expense->concept) }}" required>
        <label class="check">
            <input type="checkbox" name="has_receipt" value="1" @checked(old('has_receipt', $expense->has_receipt))>
            Tengo el justificante
        </label>
        <label for="reason">Motivo, obligatorio si no hay justificante</label>
        <input id="reason" name="reason" type="text" maxlength="500" autocomplete="off" value="{{ old('reason', $expense->reason) }}">
        <p class="notice">Sigue en borrador: no descuenta de la caja hasta que lo confirmas en Caja.</p>
        <button type="submit">Actualizar borrador</button>
    </form>
@endsection

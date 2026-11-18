@extends('layouts.app')
@section('title', 'Movimientos bancarios')
@section('content')
    <nav aria-label="Navegación" class="back-link"><a href="/dashboard">Volver al panel</a></nav>
    <h1>Movimientos bancarios</h1>
    <p class="notice">Importes en euros, con datos exclusivamente ficticios.</p>

    @if ($errors->any())
        <div class="alert alert-error" role="alert"><ul>@foreach ($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul></div>
    @endif

    @if (session('import_report'))
        @php($report = session('import_report'))
        <section class="alert alert-ok" role="status" aria-label="Resultado de la importación">
            <h2 class="mt-0 text-lg">Resultado de la importación</h2>
            <dl>
                <dt>Importados</dt><dd>{{ $report['imported'] }}</dd>
                <dt>Duplicados omitidos</dt><dd>{{ $report['duplicates'] }}</dd>
                <dt>Rechazados</dt><dd>{{ count($report['rejected']) }}</dd>
            </dl>
            @if ($report['rejected'])
                <ul>
                    @foreach ($report['rejected'] as $row)
                        <li>Línea {{ $row['line'] }}: {{ $row['reason'] }}</li>
                    @endforeach
                </ul>
            @endif
        </section>
    @endif

    @can('edit-treasury')
        <form method="post" action="/bank/imports" enctype="multipart/form-data" class="form-block">
            @csrf
            <label for="file" class="mt-0">Archivo CSV con las columnas fecha, importe y concepto</label>
            <input id="file" name="file" type="file" accept=".csv,.txt" required>
            <p class="notice">Fechas dd/mm/aaaa o aaaa-mm-dd; importes como 10,50 o -10.50. El archivo no se guarda: solo se conservan los movimientos y el registro de la importación. No subas extractos reales.</p>
            <button type="submit">Importar extracto</button>
        </form>
    @endcan

    <h2>Importaciones</h2>
    @if ($imports->isNotEmpty())
        <div class="board">
            @foreach ($imports as $import)
                <article class="entry">
                    <h3 class="mt-0">{{ $import->created_at->format('d/m/Y H:i') }} · {{ $import->user->email }}</h3>
                    <dl>
                        <dt>Importados</dt><dd>{{ $import->imported_count }}</dd>
                        <dt>Duplicados</dt><dd>{{ $import->duplicate_count }}</dd>
                        <dt>Rechazados</dt><dd>{{ $import->rejected_count }}</dd>
                    </dl>
                </article>
            @endforeach
        </div>
    @else
        <div class="empty">
            <p>Todavía no se ha importado ningún extracto.</p>
            @can('edit-treasury')
                <a href="#file" class="btn btn-secondary btn-sm">Elegir un archivo CSV</a>
            @endcan
        </div>
    @endif

    <h2>Movimientos</h2>
    @if ($movements->isNotEmpty())
        <div class="board">
            <div class="board-head" aria-hidden="true"><span>Fecha</span><span>Concepto</span><span>Importe</span></div>
            @foreach ($movements as $movement)
                <article class="entry movement">
                    <h3 class="mt-0">{{ $movement->concept }}</h3>
                    <dl>
                        <dt>Fecha</dt><dd>{{ $movement->occurred_on->format('d/m/Y') }}</dd>
                        <dt>Importe</dt><dd @class(['amount', 'amount-out' => $movement->amount_cents < 0, 'amount-in' => $movement->amount_cents > 0])>{{ \App\Modules\Fees\Support\Money::format($movement->amount_cents) }}</dd>
                    </dl>
                    @php($linkedPayments = $linked->get($movement->id, collect()))
                    @php($income = $incomes->get($movement->id))
                    @php($left = $remaining[$movement->id] ?? 0)
                    @foreach ($linkedPayments as $linkedPayment)
                        <p class="notice linked">Conciliado con {{ $linkedPayment->due->member->name }} · {{ $linkedPayment->due->feeYear->year }} · {{ \App\Modules\Fees\Support\Money::format($linkedPayment->amount_cents) }}</p>
                        @can('edit-treasury')
                            <form method="post" action="/bank/payments/{{ $linkedPayment->id }}/void" class="form-block reconcile">
                                @csrf
                                <label for="void-payment-{{ $linkedPayment->id }}" class="mt-0">Motivo de la anulación</label>
                                <input id="void-payment-{{ $linkedPayment->id }}" name="reason" type="text" maxlength="500" required>
                                <button type="submit" class="btn-danger btn-sm mt-3">Anular y liberar esta parte</button>
                            </form>
                        @endcan
                    @endforeach
                    @if ($income)
                        <p class="notice linked">Clasificado como otro ingreso · {{ $income->categoryLabel() }} · {{ \App\Modules\Fees\Support\Money::format($income->amount_cents) }}</p>
                        <p class="notice">{{ $income->description }}</p>
                        @can('edit-treasury')
                            <form method="post" action="/bank/incomes/{{ $income->id }}/void" class="form-block reconcile">
                                @csrf
                                <label for="void-income-{{ $income->id }}" class="mt-0">Motivo de la anulación</label>
                                <input id="void-income-{{ $income->id }}" name="reason" type="text" maxlength="500" required>
                                <button type="submit" class="btn-danger btn-sm mt-3">Anular la clasificación</button>
                            </form>
                        @endcan
                    @endif
                    @if ($movement->amount_cents > 0 && $left > 0 && $income === null)
                        @can('edit-treasury')
                            @if ($pendingDues->isNotEmpty())
                                <form method="post" action="/bank/movements/{{ $movement->id }}/reconcile" class="form-block reconcile">
                                    @csrf
                                    <input type="hidden" name="submission_key" value="{{ \Illuminate\Support\Str::uuid() }}">
                                    <p class="notice linked">Quedan {{ \App\Modules\Fees\Support\Money::format($left) }} por conciliar</p>
                                    <p class="notice">Escribe el importe de cada cuota que pague este abono. Una cuota vacía no participa y la suma debe dar exactamente lo que queda por conciliar.</p>
                                    @foreach ($pendingDues as $due)
                                        <label for="split-{{ $movement->id }}-{{ $due->id }}">{{ $due->member->name }} · {{ $due->feeYear->year }} · pendiente {{ \App\Modules\Fees\Support\Money::format($due->pendingCents()) }}</label>
                                        <input id="split-{{ $movement->id }}-{{ $due->id }}" name="allocations[{{ $due->id }}]" type="text" inputmode="decimal" autocomplete="off" placeholder="0,00">
                                    @endforeach
                                    <button type="submit" class="btn-secondary btn-sm mt-3">Conciliar el reparto</button>
                                </form>
                            @else
                                <p class="notice">No hay cuotas pendientes a las que asignar este abono.</p>
                            @endif
                            @if ($left === $movement->amount_cents)
                                <form method="post" action="/bank/movements/{{ $movement->id }}/incomes" class="form-block reconcile">
                                    @csrf
                                    <input type="hidden" name="submission_key" value="{{ \Illuminate\Support\Str::uuid() }}">
                                    <p class="notice">Si este abono no es dinero para las cuotas, clasifícalo como otro ingreso. El saldo del banco no cambia: el abono deja de estar pendiente de conciliación.</p>
                                    <label for="income-category-{{ $movement->id }}">Categoría</label>
                                    <select id="income-category-{{ $movement->id }}" name="category" required>
                                        <option value="">Elige una categoría</option>
                                        @foreach ($categories as $value => $label)
                                            <option value="{{ $value }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                    <label for="income-description-{{ $movement->id }}">Descripción</label>
                                    <input id="income-description-{{ $movement->id }}" name="description" type="text" maxlength="200" required>
                                    <button type="submit" class="btn-secondary btn-sm mt-3">Clasificar como otro ingreso</button>
                                </form>
                            @else
                                <p class="notice">Un abono con parte asignada a cuotas no se puede clasificar como otro ingreso. Anula esos pagos o resuelve el sobrante cuando corresponda.</p>
                            @endif
                        @endcan
                    @endif
                </article>
            @endforeach
        </div>
    @else
        <div class="empty">
            <p>No hay movimientos importados.</p>
        </div>
    @endif
    <nav aria-label="Páginas de movimientos" class="filters">
        @if ($movements->previousPageUrl())<a href="{{ $movements->previousPageUrl() }}">Más recientes</a>@endif
        @if ($movements->nextPageUrl())<a href="{{ $movements->nextPageUrl() }}">Más antiguos</a>@endif
    </nav>
@endsection

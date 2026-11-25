@extends('layouts.app')
@section('title', 'Movimientos bancarios')
@section('content')
    {{-- A demo session walks this real screen, so it hides the controls the demo bounces. --}}
    @php($demoSession = session(\App\Modules\Demo\Support\DemoSession::MARK) === true)
    <nav aria-label="Navegación" class="back-link">
        <a href="/dashboard">Volver al panel</a>
    </nav>
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

    @if (! $demoSession)
        @can('edit-treasury')
            <form method="post" action="/bank/imports" enctype="multipart/form-data" class="form-block">
                @csrf
                <label for="file" class="mt-0">Archivo CSV con las columnas fecha, importe y concepto</label>
                <input id="file" name="file" type="file" accept=".csv,.txt" required>
                <p class="notice">Fechas dd/mm/aaaa o aaaa-mm-dd; importes como 10,50 o -10.50. El archivo no se guarda: solo se conservan los movimientos y el registro de la importación. No subas extractos reales.</p>
                <button type="submit">Importar extracto</button>
            </form>
        @endcan
    @endif

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
            @if (! $demoSession)
                @can('edit-treasury')
                    <a href="#file" class="btn btn-secondary btn-sm">Elegir un archivo CSV</a>
                @endcan
            @endif
        </div>
    @endif

    <h2>Movimientos</h2>
    @can('edit-treasury')
        <form method="get" action="/bank" class="form-block">
            <label for="socio" class="mt-0">Buscar las cuotas pendientes de un socio</label>
            <input id="socio" name="socio" type="search" value="{{ $memberFilter }}" maxlength="100" placeholder="Nombre del socio">
            <p class="notice">Los formularios de conciliación muestran como máximo {{ $pendingCap }} cuotas pendientes por abono, de cualquier año. Si la cuota que buscas no aparece, escribe el nombre del socio.</p>
            <button type="submit" class="btn-secondary btn-sm">Filtrar</button>
            @if ($memberFilter !== '')
                <a href="/bank" class="btn btn-secondary btn-sm">Quitar el filtro</a>
            @endif
        </form>
    @endcan
    @if ($pendingTotal !== null)
        <p class="notice">Se muestran las primeras {{ $pendingCap }} cuotas pendientes de {{ $pendingTotal }}. Filtra por socio para encontrar otra.</p>
    @endif
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
                    @php($expense = $expenses->get($movement->id))
                    @php($transfer = $transfers->get($movement->id, collect()->first()))
                    @php($left = $remaining[$movement->id] ?? 0)
                    @php($reconciled = $movement->amount_cents - $left)
                    @if ($transfer)
                        <p class="notice linked">Traspaso · {{ $transfer->directionLabel() }} · {{ \App\Modules\Fees\Support\Money::format($transfer->amount()) }}</p>
                        <p class="notice">{{ $transfer->description }}</p>
                        @if (! $demoSession)
                            @can('edit-treasury')
                                <form method="post" action="/bank/transfers/{{ $transfer->id }}/void" class="form-block reconcile">
                                    @csrf
                                    <label for="void-transfer-{{ $transfer->id }}" class="mt-0">Motivo de la anulación</label>
                                    <input id="void-transfer-{{ $transfer->id }}" name="reason" type="text" maxlength="500" required>
                                    <button type="submit" class="btn-danger btn-sm mt-3">Anular el traspaso</button>
                                </form>
                            @endcan
                        @endif
                    @endif
                    @if ($expense)
                        <p class="notice linked">Clasificado como gasto · {{ $expense->categoryLabel() }} · {{ \App\Modules\Fees\Support\Money::format($expense->amount()) }}</p>
                        <p class="notice">{{ $expense->description }}</p>
                        @if (! $demoSession)
                            @can('edit-treasury')
                                <form method="post" action="/bank/expenses/{{ $expense->id }}/void" class="form-block reconcile">
                                    @csrf
                                    <label for="void-expense-{{ $expense->id }}" class="mt-0">Motivo de la anulación</label>
                                    <input id="void-expense-{{ $expense->id }}" name="reason" type="text" maxlength="500" required>
                                    <button type="submit" class="btn-danger btn-sm mt-3">Anular el gasto</button>
                                </form>
                            @endcan
                        @endif
                    @endif
                    @foreach ($linkedPayments as $linkedPayment)
                        <p class="notice linked">Conciliado con {{ $linkedPayment->due->member->name }} · {{ $linkedPayment->due->feeYear->year }} · {{ \App\Modules\Fees\Support\Money::format($linkedPayment->amount_cents) }}</p>
                        @if (! $demoSession)
                            @can('edit-treasury')
                                <form method="post" action="/bank/payments/{{ $linkedPayment->id }}/void" class="form-block reconcile">
                                    @csrf
                                    <label for="void-payment-{{ $linkedPayment->id }}" class="mt-0">Motivo de la anulación</label>
                                    <input id="void-payment-{{ $linkedPayment->id }}" name="reason" type="text" maxlength="500" required>
                                    <button type="submit" class="btn-danger btn-sm mt-3">Anular y liberar esta parte</button>
                                </form>
                            @endcan
                        @endif
                    @endforeach
                    @if ($income)
                        <p class="notice linked">Clasificado como otro ingreso · {{ $income->categoryLabel() }} · {{ \App\Modules\Fees\Support\Money::format($income->amount_cents) }}</p>
                        <p class="notice">{{ $income->description }}</p>
                        @if (! $demoSession)
                            @can('edit-treasury')
                                <form method="post" action="/bank/incomes/{{ $income->id }}/void" class="form-block reconcile">
                                    @csrf
                                    <label for="void-income-{{ $income->id }}" class="mt-0">Motivo de la anulación</label>
                                    <input id="void-income-{{ $income->id }}" name="reason" type="text" maxlength="500" required>
                                    <button type="submit" class="btn-danger btn-sm mt-3">Anular la clasificación</button>
                                </form>
                            @endcan
                        @endif
                    @endif
                    @if ($transfer === null && $expense === null && $income === null && $linkedPayments->isEmpty())
                        @if (! $demoSession)
                            @can('edit-treasury')
                                <form method="post" action="/bank/movements/{{ $movement->id }}/transfers" class="form-block reconcile">
                                    @csrf
                                    <input type="hidden" name="submission_key" value="{{ \Illuminate\Support\Str::uuid() }}">
                                    <input type="hidden" name="direction" value="{{ $movement->amount_cents < 0 ? 'to_cash' : 'to_bank' }}">
                                    <p class="notice">
                                        @if ($movement->amount_cents < 0)
                                            Si este cargo es dinero que sacaste del banco para la caja, márcalo como traspaso: la caja sube y el banco no cambia.
                                        @else
                                            Si este abono es efectivo que llevaste de la caja al banco, márcalo como traspaso: la caja baja y el banco no cambia.
                                        @endif
                                    </p>
                                    <label for="transfer-description-{{ $movement->id }}">Descripción del traspaso</label>
                                    <input id="transfer-description-{{ $movement->id }}" name="description" type="text" maxlength="200" required>
                                    <button type="submit" class="btn-secondary btn-sm mt-3">Marcar traspaso</button>
                                </form>
                            @endcan
                        @endif
                    @endif
                    @if ($movement->amount_cents < 0 && $expense === null && $transfer === null && $linkedPayments->isEmpty())
                        @if (! $demoSession)
                            @can('edit-treasury')
                                <form method="post" action="/bank/movements/{{ $movement->id }}/expenses" class="form-block reconcile">
                                    @csrf
                                    <input type="hidden" name="submission_key" value="{{ \Illuminate\Support\Str::uuid() }}">
                                    <p class="notice">Si conoces el motivo de este cargo, clasifícalo como gasto. El saldo del banco no cambia: el cargo ya está restado en el extracto.</p>
                                    <label for="expense-category-{{ $movement->id }}">Categoría</label>
                                    <select id="expense-category-{{ $movement->id }}" name="category" required>
                                        <option value="">Elige una categoría</option>
                                        @foreach (\App\Modules\Bank\Models\BankExpense::CATEGORIES as $value => $label)
                                            <option value="{{ $value }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                    <label for="expense-description-{{ $movement->id }}">Descripción del gasto</label>
                                    <input id="expense-description-{{ $movement->id }}" name="description" type="text" maxlength="200" required>
                                    <button type="submit" class="btn-secondary btn-sm mt-3">Clasificar el cargo</button>
                                </form>
                            @endcan
                        @endif
                    @endif
                    <x-reconcile-form
                        :movement="$movement"
                        :pending-dues="$pendingDues"
                        :left="$left"
                        :reconciled="$reconciled"
                        :categories="$categories"
                        :income="$income"
                        :transfer="$transfer"
                    />
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

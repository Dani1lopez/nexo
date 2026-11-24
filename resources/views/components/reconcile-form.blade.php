@props([
    'movement',
    'pendingDues',
    'left',
    'reconciled',
    'categories',
    'income' => null,
    'transfer' => null,
])

@php($demoSession = session(\App\Modules\Demo\Support\DemoSession::MARK) === true)

{{-- The real reconciliation block of /bank: the split of one credit over the pending dues. --}}
@if ($movement->amount_cents > 0 && $left > 0 && $income === null && $transfer === null)
    @can('edit-treasury')
        @if ($pendingDues->isNotEmpty())
            <form method="post" action="/bank/movements/{{ $movement->id }}/reconcile" class="form-block reconcile">
                @csrf
                <input type="hidden" name="submission_key" value="{{ \Illuminate\Support\Str::uuid() }}">
                {{-- The demo session is the only one that updates the remainder while typing (T10), so it
                     is the only one that needs a live region; the real screen keeps its own markup. --}}
                @if ($demoSession)
                    <output class="notice linked" aria-live="polite">Quedan {{ \App\Modules\Fees\Support\Money::format($left) }} por conciliar.</output>
                @else
                    <p class="notice linked">Quedan {{ \App\Modules\Fees\Support\Money::format($left) }} por conciliar.</p>
                @endif
                @if ($reconciled > 0)
                    <p class="notice">Ya se han conciliado {{ \App\Modules\Fees\Support\Money::format($reconciled) }} de este abono; el resto sigue pendiente de destino.</p>
                @endif
                <p class="notice">Escribe el importe de cada cuota que pague este abono. Una cuota vacía no participa y la suma no puede superar lo que queda por conciliar: lo que no asignes seguirá pendiente.</p>
                @foreach ($pendingDues as $due)
                    <label for="split-{{ $movement->id }}-{{ $due->id }}">{{ $due->member->name }} · {{ $due->feeYear->year }} · pendiente {{ \App\Modules\Fees\Support\Money::format($due->pendingCents()) }}</label>
                    <input id="split-{{ $movement->id }}-{{ $due->id }}" name="allocations[{{ $due->id }}]" type="text" inputmode="decimal" autocomplete="off" placeholder="0,00">
                @endforeach
                <button type="submit" class="btn-secondary btn-sm mt-3">Conciliar el reparto</button>
            </form>
            {{-- Issue #74: the demo's step of the guide, in the same block as the control it names. --}}
            <x-guide-notice :step="\App\Modules\Demo\Support\GuideSteps::BANK" />
        @else
            <p class="notice">No hay cuotas pendientes a las que asignar este abono.</p>
        @endif
        {{-- The demo bounces this write, so the demo never shows the control that would. --}}
        @if (! $demoSession)
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
                    <label for="income-description-{{ $movement->id }}">Descripción del ingreso</label>
                    <input id="income-description-{{ $movement->id }}" name="description" type="text" maxlength="200" required>
                    <button type="submit" class="btn-secondary btn-sm mt-3">Clasificar como otro ingreso</button>
                </form>
            @else
                <p class="notice">Un abono con parte asignada a cuotas no se puede clasificar como otro ingreso. Anula esos pagos o resuelve el sobrante cuando corresponda.</p>
            @endif
        @endif
    @endcan
@endif

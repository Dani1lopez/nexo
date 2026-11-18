@extends('layouts.app')
@section('title', 'Cuota de '.$feeYear->year)
@section('content')
    @php($money = fn (int $cents) => \App\Modules\Fees\Support\Money::format($cents))
    <nav aria-label="Navegación" class="back-link"><a href="/fees">Volver a cuotas anuales</a></nav>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h1 class="mb-0">Cuota de {{ $feeYear->year }}</h1>
        @can('edit-treasury')
            <a href="/fees/{{ $feeYear->id }}/edit" class="btn btn-secondary btn-sm">Cambiar el importe</a>
        @endcan
    </div>
    <p>Importe: <strong>{{ $money($feeYear->amount_cents) }}</strong></p>
    <div class="tally" role="group" aria-label="Resumen del año">
        <p><span>Total a cobrar</span><strong>{{ $money($totals['payableCents']) }}</strong></p>
        <p><span>Cobrado</span><strong>{{ $money($totals['paidCents']) }}</strong></p>
        <p><span>Por cobrar</span><strong>{{ $money($totals['pendingCents']) }}</strong></p>
    </div>
    @if ($errors->any())
        <div class="alert alert-error" role="alert">
            <ul>
                @foreach ($errors->all() as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        </div>
    @endif
    @forelse ($dues as $due)
        @php($active = $due->activeExemption())
        <article class="entry due due-{{ $due->status() }}">
            <div class="person"><x-avatar :name="$due->member->name" /><h2>{{ $due->member->name }}</h2></div>
            <p>Cuota: {{ $money($feeYear->amount_cents) }} · A pagar: {{ $money($due->payableCents()) }}</p>
            <p>Pagado: {{ $money($due->paidCents()) }} · Pendiente: {{ $money($due->pendingCents()) }}</p>
            <p>Estado: <strong class="badge badge-{{ $due->status() }}">{{ ['pending' => 'Pendiente de pago', 'partial' => 'Parcial', 'paid' => 'Pagada', 'exempt' => 'Exenta'][$due->status()] }}</strong></p>
            @if ($due->payments->isNotEmpty())
                <h3>Pagos</h3>
                <ul>
                    @foreach ($due->payments as $payment)
                        <li>
                            {{ $payment->paid_on->format('d/m/Y') }} · {{ $money($payment->amount_cents) }} · {{ \App\Modules\Fees\Models\Payment::METHODS[$payment->method] ?? $payment->method }}
                            @if ($payment->note) · Nota: {{ $payment->note }} @endif
                            @if ($payment->isActive())
                                @can('edit-treasury')
                                    <form method="post" action="/payments/{{ $payment->id }}/void" class="form-block">
                                        @csrf
                                        <label for="void-{{ $payment->id }}" class="mt-0">Motivo de la anulación del pago de {{ $payment->paid_on->format('d/m/Y') }} ({{ $due->member->name }})</label>
                                        <input id="void-{{ $payment->id }}" name="reason" type="text" maxlength="500" required>
                                        <button type="submit" class="btn-danger btn-sm mt-3">Anular pago</button>
                                    </form>
                                @endcan
                            @else
                                — Anulado: {{ $payment->void_reason }}
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
            @can('edit-treasury')
                @if ($due->pendingCents() > 0)
                    <form method="post" action="/dues/{{ $due->id }}/payments" class="form-block">
                        @csrf
                        <input type="hidden" name="submission_key" value="{{ \Illuminate\Support\Str::uuid() }}">
                        <p class="notice mt-0">No escribas datos personales reales en la nota.</p>
                        <div class="grid items-end gap-x-4 sm:grid-cols-2 lg:grid-cols-4">
                            <div>
                                <label for="pay-amount-{{ $due->id }}">Importe del pago en euros ({{ $due->member->name }})</label>
                                <input id="pay-amount-{{ $due->id }}" name="amount" type="text" inputmode="decimal" autocomplete="off" required>
                            </div>
                            <div>
                                <label for="pay-date-{{ $due->id }}">Fecha del pago ({{ $due->member->name }})</label>
                                <input id="pay-date-{{ $due->id }}" name="paid_on" type="date" value="{{ now()->toDateString() }}" required>
                            </div>
                            <div>
                                <label for="pay-method-{{ $due->id }}">Forma de pago ({{ $due->member->name }})</label>
                                <select id="pay-method-{{ $due->id }}" name="method" required>
                                    @foreach (\App\Modules\Fees\Models\Payment::METHODS as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label for="pay-note-{{ $due->id }}">Nota opcional ({{ $due->member->name }})</label>
                                <input id="pay-note-{{ $due->id }}" name="note" type="text" maxlength="255">
                            </div>
                        </div>
                        <button type="submit">Registrar pago</button>
                    </form>
                @endif
            @endcan
            @if ($due->exemptions->isNotEmpty())
                <h3>Historial de exenciones</h3>
                <ul>
                    @foreach ($due->exemptions as $exemption)
                        <li>
                            Exención: {{ $exemption->reason }}
                            @if ($exemption->revoked_at)
                                — Anulada: {{ $exemption->revoked_reason }}
                            @else
                                — Vigente
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
            @can('edit-treasury')
                @if ($active)
                    <form method="post" action="/exemptions/{{ $active->id }}/revoke" class="form-block">
                        @csrf
                        <label for="revoke-{{ $due->id }}" class="mt-0">Motivo de la anulación ({{ $due->member->name }})</label>
                        <input id="revoke-{{ $due->id }}" name="reason" type="text" maxlength="500" required>
                        <button type="submit" class="btn-danger">Anular exención</button>
                    </form>
                @else
                    <form method="post" action="/dues/{{ $due->id }}/exemptions" class="form-block">
                        @csrf
                        <label for="grant-{{ $due->id }}" class="mt-0">Motivo de la exención ({{ $due->member->name }})</label>
                        <input id="grant-{{ $due->id }}" name="reason" type="text" maxlength="500" required>
                        <button type="submit" class="btn-secondary">Conceder exención</button>
                    </form>
                @endif
            @endcan
        </article>
    @empty
        <p>Ningún socio tiene asignada esta cuota.</p>
    @endforelse
    @if ($dues->hasPages())
        <nav aria-label="Páginas de cuotas" class="filters">
            @if ($dues->previousPageUrl())<a href="{{ $dues->previousPageUrl() }}">Anterior</a>@endif
            <span>Página {{ $dues->currentPage() }} de {{ $dues->lastPage() }}</span>
            @if ($dues->nextPageUrl())<a href="{{ $dues->nextPageUrl() }}">Siguiente</a>@endif
        </nav>
    @endif
@endsection

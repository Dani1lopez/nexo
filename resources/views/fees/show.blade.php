@extends('layouts.access')
@section('title', 'Cuota de '.$feeYear->year)
@section('content')
    @php($money = fn (int $cents) => \App\Modules\Fees\Support\Money::format($cents))
    <nav aria-label="Navegación"><a href="/fees">Volver a cuotas anuales</a></nav>
    <h1>Cuota de {{ $feeYear->year }}</h1>
    <p>Importe: <strong>{{ $money($feeYear->amount_cents) }}</strong></p>
    @can('edit-treasury')
        <p><a href="/fees/{{ $feeYear->id }}/edit">Cambiar el importe</a></p>
    @endcan
    @if ($errors->any())
        <div class="error" role="alert">
            <ul>
                @foreach ($errors->all() as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        </div>
    @endif
    @forelse ($dues as $due)
        @php($active = $due->activeExemption())
        <article class="member">
            <h2>{{ $due->member->name }}</h2>
            <p>Cuota: {{ $money($feeYear->amount_cents) }} · A pagar: {{ $money($due->payableCents()) }}</p>
            <p>Pagado: {{ $money($due->paidCents()) }} · Pendiente: {{ $money($due->pendingCents()) }}</p>
            <p>Estado: <strong>{{ ['pending' => 'Pendiente de pago', 'partial' => 'Parcial', 'paid' => 'Pagada', 'exempt' => 'Exenta'][$due->status()] }}</strong></p>
            @if ($due->payments->isNotEmpty())
                <h3>Pagos</h3>
                <ul>
                    @foreach ($due->payments as $payment)
                        <li>
                            {{ $payment->paid_on->format('d/m/Y') }} · {{ $money($payment->amount_cents) }} · {{ \App\Modules\Fees\Models\Payment::METHODS[$payment->method] ?? $payment->method }}
                            @if ($payment->note) · Nota: {{ $payment->note }} @endif
                            @if ($payment->isActive())
                                @can('edit-treasury')
                                    <form method="post" action="/payments/{{ $payment->id }}/void">
                                        @csrf
                                        <label for="void-{{ $payment->id }}">Motivo de la anulación del pago de {{ $payment->paid_on->format('d/m/Y') }} ({{ $due->member->name }})</label>
                                        <input id="void-{{ $payment->id }}" name="reason" type="text" maxlength="500" required>
                                        <button type="submit">Anular pago</button>
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
                    <form method="post" action="/dues/{{ $due->id }}/payments">
                        @csrf
                        <input type="hidden" name="submission_key" value="{{ \Illuminate\Support\Str::uuid() }}">
                        <p class="notice">No escribas datos personales reales en la nota.</p>
                        <label for="pay-amount-{{ $due->id }}">Importe del pago en euros ({{ $due->member->name }})</label>
                        <input id="pay-amount-{{ $due->id }}" name="amount" type="text" inputmode="decimal" autocomplete="off" required>
                        <label for="pay-date-{{ $due->id }}">Fecha del pago ({{ $due->member->name }})</label>
                        <input id="pay-date-{{ $due->id }}" name="paid_on" type="date" value="{{ now()->toDateString() }}" required>
                        <label for="pay-method-{{ $due->id }}">Forma de pago ({{ $due->member->name }})</label>
                        <select id="pay-method-{{ $due->id }}" name="method" required>
                            @foreach (\App\Modules\Fees\Models\Payment::METHODS as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        <label for="pay-note-{{ $due->id }}">Nota opcional ({{ $due->member->name }})</label>
                        <input id="pay-note-{{ $due->id }}" name="note" type="text" maxlength="255">
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
                    <form method="post" action="/exemptions/{{ $active->id }}/revoke">
                        @csrf
                        <label for="revoke-{{ $due->id }}">Motivo de la anulación ({{ $due->member->name }})</label>
                        <input id="revoke-{{ $due->id }}" name="reason" type="text" maxlength="500" required>
                        <button type="submit">Anular exención</button>
                    </form>
                @else
                    <form method="post" action="/dues/{{ $due->id }}/exemptions">
                        @csrf
                        <label for="grant-{{ $due->id }}">Motivo de la exención ({{ $due->member->name }})</label>
                        <input id="grant-{{ $due->id }}" name="reason" type="text" maxlength="500" required>
                        <button type="submit">Conceder exención</button>
                    </form>
                @endif
            @endcan
        </article>
    @empty
        <p>Ningún socio tiene asignada esta cuota.</p>
    @endforelse
@endsection

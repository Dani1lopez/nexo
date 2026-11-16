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
        @php($active = $due->exemptions->firstWhere('revoked_at', null))
        <article class="member">
            <h2>{{ $due->member->name }}</h2>
            <p>Cuota: {{ $money($feeYear->amount_cents) }} · A pagar: {{ $money($active ? 0 : $feeYear->amount_cents) }}</p>
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

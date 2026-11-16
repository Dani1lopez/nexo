@extends('layouts.app')
@section('title', 'Panel')
@section('content')
    <h1>Tu acceso</h1>
    <p>Sesión iniciada como <strong>{{ auth()->user()->name }}</strong>.</p>
    <dl>
        <dt>Correo</dt><dd>{{ auth()->user()->email }}</dd>
        <dt>Rol</dt><dd>{{ auth()->user()->role === 'treasury' ? 'Tesorería' : 'Junta' }}</dd>
    </dl>
    <section aria-labelledby="resumen">
        <h2 id="resumen">Resumen</h2>
        @if ($overview['year'])
            <div class="tally" role="group" aria-label="Cifras de la cuota de {{ $overview['year'] }}">
                <p><span>Socios activos</span><strong>{{ $overview['activeMembers'] }}</strong></p>
                <p><span>Pendiente de cobro en {{ $overview['year'] }}</span><strong>{{ \App\Modules\Fees\Support\Money::format($overview['pendingCents']) }}</strong><span>{{ $overview['pendingDues'] }} {{ $overview['pendingDues'] === 1 ? 'cuota pendiente' : 'cuotas pendientes' }}</span></p>
                <p><span>Cobrado en {{ $overview['year'] }}</span><strong>{{ \App\Modules\Fees\Support\Money::format($overview['paidCents']) }}</strong><span>de {{ \App\Modules\Fees\Support\Money::format($overview['payableCents']) }} a cobrar</span></p>
            </div>
        @else
            <div class="empty">
                <p>Todavía no hay cuotas fijadas, así que no hay cifras de cobro que mostrar. Hay {{ $overview['activeMembers'] }} {{ $overview['activeMembers'] === 1 ? 'socio activo' : 'socios activos' }}.</p>
            </div>
        @endif
        <p class="notice">
            @if ($overview['lastImportAt'])
                Último extracto importado el {{ $overview['lastImportAt']->format('d/m/Y') }}, con {{ $overview['movements'] }} {{ $overview['movements'] === 1 ? 'movimiento' : 'movimientos' }} en total.
            @else
                Todavía no se ha importado ningún extracto bancario.
            @endif
        </p>
    </section>
    <p class="alert alert-info">Este panel es de consulta. Las operaciones económicas todavía no están disponibles.</p>
    <div class="link-grid">
        <a class="link-card" href="/members"><strong>Ver socios</strong><span>Altas, bajas y datos de contacto.</span></a>
        <a class="link-card" href="/fees"><strong>Cuotas anuales</strong><span>Quién ha pagado y quién debe.</span></a>
        <a class="link-card" href="/bank"><strong>Movimientos bancarios</strong><span>El extracto del banco, importado en CSV.</span></a>
    </div>
@endsection

@extends('layouts.access')
@section('title', 'Movimientos bancarios')
@section('content')
    <nav aria-label="Navegación"><a href="/dashboard">Volver al panel</a></nav>
    <h1>Movimientos bancarios</h1>
    <p>Importes en euros, con datos exclusivamente ficticios.</p>

    @if ($errors->any())
        <div class="error" role="alert"><ul>@foreach ($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul></div>
    @endif

    @if (session('import_report'))
        @php($report = session('import_report'))
        <section class="member" role="status" aria-label="Resultado de la importación">
            <h2>Resultado de la importación</h2>
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
        <form method="post" action="/bank/imports" enctype="multipart/form-data">
            @csrf
            <label for="file">Archivo CSV con las columnas fecha, importe y concepto</label>
            <input id="file" name="file" type="file" accept=".csv,.txt" required>
            <p class="notice">Fechas dd/mm/aaaa o aaaa-mm-dd; importes como 10,50 o -10.50. El archivo no se guarda: solo se conservan los movimientos y el registro de la importación. No subas extractos reales.</p>
            <button type="submit">Importar extracto</button>
        </form>
    @endcan

    <h2>Importaciones</h2>
    @forelse ($imports as $import)
        <article class="member">
            <h3>{{ $import->created_at->format('d/m/Y H:i') }} · {{ $import->user->email }}</h3>
            <dl>
                <dt>Importados</dt><dd>{{ $import->imported_count }}</dd>
                <dt>Duplicados</dt><dd>{{ $import->duplicate_count }}</dd>
                <dt>Rechazados</dt><dd>{{ $import->rejected_count }}</dd>
            </dl>
        </article>
    @empty
        <p>Todavía no se ha importado ningún extracto.</p>
    @endforelse

    <h2>Movimientos</h2>
    @forelse ($movements as $movement)
        <article class="member">
            <h3>{{ $movement->concept }}</h3>
            <dl>
                <dt>Fecha</dt><dd>{{ $movement->occurred_on->format('d/m/Y') }}</dd>
                <dt>Importe</dt><dd>{{ \App\Modules\Fees\Support\Money::format($movement->amount_cents) }}</dd>
            </dl>
        </article>
    @empty
        <p>No hay movimientos importados.</p>
    @endforelse
    <nav aria-label="Páginas de movimientos" class="filters">
        @if ($movements->previousPageUrl())<a href="{{ $movements->previousPageUrl() }}">Más recientes</a>@endif
        @if ($movements->nextPageUrl())<a href="{{ $movements->nextPageUrl() }}">Más antiguos</a>@endif
    </nav>
@endsection

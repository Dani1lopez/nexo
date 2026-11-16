@extends('layouts.app')
@section('title', 'Socios')
@section('content')
    <nav aria-label="Navegación" class="back-link"><a href="/dashboard">Volver al panel</a></nav>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h1 class="mb-0">Socios</h1>
        @can('edit-treasury')
            <a href="/members/create" class="btn mt-0">Nuevo socio</a>
        @endcan
    </div>
    <p class="notice">Datos exclusivamente ficticios.</p>
    <nav aria-label="Filtrar por estado" class="filters">
        @foreach (['activos' => 'Activos', 'bajas' => 'Bajas', 'todos' => 'Todos'] as $key => $label)
            <a href="/members?estado={{ $key }}" @if ($filter === $key) aria-current="page" @endif>{{ $label }}</a>
        @endforeach
    </nav>
    @if ($members->isNotEmpty())
        <div class="board">
            @foreach ($members as $member)
                <article class="entry">
                    <div class="person"><x-avatar :name="$member->name" /><h2>{{ $member->name }}</h2></div>
                    <dl>
                        <dt>Correo</dt><dd>{{ $member->email }}</dd>
                        <dt>Fecha de alta</dt><dd>{{ $member->joined_on->format('d/m/Y') }}</dd>
                        <dt>Estado</dt><dd>{{ $member->status === 'active' ? 'Activo' : 'De baja' }}</dd>
                    </dl>
                    @can('edit-treasury')
                        <div class="mt-3 flex flex-wrap items-center gap-3">
                            <a href="/members/{{ $member->id }}/edit" class="btn btn-secondary btn-sm">Editar {{ $member->name }}</a>
                            @if ($member->status === 'active')
                                <form method="post" action="/members/{{ $member->id }}/deactivate">@csrf<button type="submit" class="btn-danger btn-sm">Dar de baja a {{ $member->name }}</button></form>
                            @else
                                <form method="post" action="/members/{{ $member->id }}/reactivate">@csrf<button type="submit" class="btn-secondary btn-sm">Reactivar a {{ $member->name }}</button></form>
                            @endif
                        </div>
                    @endcan
                </article>
            @endforeach
        </div>
    @else
        <div class="empty">
            <p>No hay socios que mostrar con este filtro.</p>
            @can('edit-treasury')
                <a href="/members/create" class="btn btn-secondary btn-sm">Añadir un socio</a>
            @endcan
        </div>
    @endif
@endsection

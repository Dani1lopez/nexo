@extends('layouts.access')
@section('title', 'Socios')
@section('content')
    <nav aria-label="Navegación"><a href="/dashboard">Volver al panel</a></nav>
    <h1>Socios</h1>
    <p>Datos exclusivamente ficticios.</p>
    @can('edit-treasury')
        <p><a href="/members/create">Nuevo socio</a></p>
    @endcan
    <nav aria-label="Filtrar por estado" class="filters">
        @foreach (['activos' => 'Activos', 'bajas' => 'Bajas', 'todos' => 'Todos'] as $key => $label)
            <a href="/members?estado={{ $key }}" @if ($filter === $key) aria-current="page" @endif>{{ $label }}</a>
        @endforeach
    </nav>
    @forelse ($members as $member)
        <article class="member">
            <h2>{{ $member->name }}</h2>
            <dl>
                <dt>Correo</dt><dd>{{ $member->email }}</dd>
                <dt>Fecha de alta</dt><dd>{{ $member->joined_on->format('d/m/Y') }}</dd>
                <dt>Estado</dt><dd>{{ $member->status === 'active' ? 'Activo' : 'De baja' }}</dd>
            </dl>
            @can('edit-treasury')
                <a href="/members/{{ $member->id }}/edit">Editar {{ $member->name }}</a>
                @if ($member->status === 'active')
                    <form method="post" action="/members/{{ $member->id }}/deactivate">@csrf<button type="submit">Dar de baja a {{ $member->name }}</button></form>
                @else
                    <form method="post" action="/members/{{ $member->id }}/reactivate">@csrf<button type="submit">Reactivar a {{ $member->name }}</button></form>
                @endif
            @endcan
        </article>
    @empty
        <p>No hay socios que mostrar con este filtro.</p>
    @endforelse
@endsection

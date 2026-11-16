<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $__env->hasSection('full_title') ? $__env->yieldContent('full_title') : $__env->yieldContent('title').' — Nexo' }}</title>
    @vite('resources/css/app.css')
</head>
@php
    // Each section is a "line" with its own colour; the body class selects it.
    $line = request()->is('members*') ? 'socios' : (request()->is('fees*') ? 'cuotas' : (request()->is('bank*') ? 'movimientos' : 'panel'));
@endphp
<body class="flex min-h-screen flex-col line-{{ $line }}">
    <a class="skip-link" href="#contenido">Saltar al contenido</a>
    <header class="site-header">
        <div class="site-header-inner">
            {{-- The public entry page runs without session, so it must not ask who is signed in. --}}
            @hasSection('public')
                <a class="brand" href="/">Nexo</a>
                <a class="btn btn-secondary btn-sm ml-auto" href="/login">Acceder</a>
            @else
                @auth
                    <a class="brand" href="/dashboard">Nexo</a>
                    <div class="who">
                        <span>{{ auth()->user()->name }} · {{ auth()->user()->role === 'treasury' ? 'Tesorería' : 'Junta' }}</span>
                        <form method="post" action="/logout">@csrf<button type="submit" class="btn-secondary btn-sm">Cerrar sesión</button></form>
                    </div>
                @else
                    <a class="brand" href="/">Nexo</a>
                @endauth
            @endif
        </div>
        @if (! $__env->hasSection('public') && auth()->check())
            <div class="platform" aria-hidden="true"><div class="platform-inner">{{ ['panel' => 'Panel', 'socios' => 'Socios', 'cuotas' => 'Cuotas', 'movimientos' => 'Movimientos'][$line] }}</div></div>
        @endif
    </header>
    @if (! $__env->hasSection('public'))
        @auth
            <nav aria-label="Principal" class="route">
                <a class="stop stop-panel" href="/dashboard" @if (request()->is('dashboard')) aria-current="page" @endif>Panel</a>
                <a class="stop stop-socios" href="/members" @if (request()->is('members*')) aria-current="page" @endif>Socios</a>
                <a class="stop stop-cuotas" href="/fees" @if (request()->is('fees*')) aria-current="page" @endif>Cuotas</a>
                <a class="stop stop-movimientos" href="/bank" @if (request()->is('bank*')) aria-current="page" @endif>Movimientos</a>
            </nav>
        @endauth
    @endif
    <main id="contenido" tabindex="-1" class="@hasSection('narrow') form-page max-w-narrow @else sheet max-w-page @endif">
        @if (! $__env->hasSection('public') && session('status'))
            <p class="alert alert-ok status" role="status">{{ session('status') }}</p>
        @endif
        @yield('content')
    </main>
    <footer class="site-footer">
        <p class="mx-auto">Demo con datos ficticios · No uses datos personales ni dinero real</p>
    </footer>
</body>
</html>

<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark light">
    <title>{{ $__env->hasSection('full_title') ? $__env->yieldContent('full_title') : $__env->yieldContent('title').' — Nexo' }}</title>
    @vite(['resources/css/app.css', 'resources/js/theme.js'])
</head>
@php
    // The menu is defined once: the section accent (body class) and the current item both come from it.
    $menu = [
        ['key' => 'panel', 'label' => 'Panel', 'href' => '/dashboard', 'match' => 'dashboard'],
        ['key' => 'saldos', 'label' => 'Saldos', 'href' => '/saldos', 'match' => 'saldos*'],
        ['key' => 'socios', 'label' => 'Socios', 'href' => '/members', 'match' => 'members*'],
        ['key' => 'cuotas', 'label' => 'Cuotas', 'href' => '/fees', 'match' => 'fees*'],
        ['key' => 'movimientos', 'label' => 'Movimientos', 'href' => '/bank', 'match' => 'bank*'],
    ];
    $line = collect($menu)->first(fn ($item) => request()->is($item['match']))['key'] ?? 'panel';
    // The public entry page runs without session, so it must not ask who is signed in.
    $public = $__env->hasSection('public');
    $signedIn = ! $public && auth()->check();
    $narrow = $__env->hasSection('narrow');
@endphp
<body class="flex min-h-screen flex-col line-{{ $line }}">
    <a class="skip-link" href="#contenido">Saltar al contenido</a>
    <header class="topbar">
        <div class="frame topbar-inner">
            <a class="brand" href="{{ $signedIn ? '/dashboard' : '/' }}">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="6" cy="12" r="3.25" stroke="currentColor" stroke-width="2"/><circle cx="18" cy="12" r="3.25" fill="currentColor"/><path d="M9.25 12h5.5" stroke="currentColor" stroke-width="2"/></svg>
                Nexo
            </a>
            @if ($public)
                <button type="button" class="btn-secondary btn-sm theme-toggle ml-auto" id="theme-toggle" hidden>Modo claro</button>
                <a class="btn btn-secondary btn-sm" href="/login">Acceder</a>
            @elseif ($signedIn)
                <div class="who">
                    <button type="button" class="btn-secondary btn-sm theme-toggle" id="theme-toggle" hidden>Modo claro</button>
                    <span>{{ auth()->user()->name }} · {{ auth()->user()->role === 'treasury' ? 'Tesorería' : 'Junta' }}</span>
                    <form method="post" action="/logout">@csrf<button type="submit" class="btn-secondary btn-sm">Cerrar sesión</button></form>
                </div>
            @endif
        </div>
    </header>
    @if ($signedIn)
        <div class="frame shell">
            <nav aria-label="Principal" class="side">
                @foreach ($menu as $item)
                    <a class="stop stop-{{ $item['key'] }}" href="{{ $item['href'] }}" @if (request()->is($item['match'])) aria-current="page" @endif>{{ $item['label'] }}</a>
                @endforeach
            </nav>
    @endif
    <main id="contenido" tabindex="-1" class="{{ $signedIn ? 'content' : 'guest' }}{{ $narrow ? ($signedIn ? ' content-narrow' : ' guest-narrow') : '' }}">
        @if (! $public && session('status'))
            <p class="alert alert-ok status" role="status">{{ session('status') }}</p>
        @endif
        @yield('content')
    </main>
    @if ($signedIn)
        </div>
    @endif
    <footer class="site-footer">
        <p>Demo con datos ficticios · No uses datos personales ni dinero real</p>
    </footer>
</body>
</html>

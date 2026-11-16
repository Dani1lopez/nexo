<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark light">
    <script>try{var t=localStorage.getItem('nexo-theme');if(t==='light'||t==='dark')document.documentElement.dataset.theme=t}catch(e){}</script>
    <title>{{ $__env->hasSection('full_title') ? $__env->yieldContent('full_title') : $__env->yieldContent('title').' — Nexo' }}</title>
    @vite('resources/css/app.css')
</head>
@php
    // Each section has its own accent; the body class selects it.
    $line = request()->is('members*') ? 'socios' : (request()->is('fees*') ? 'cuotas' : (request()->is('bank*') ? 'movimientos' : 'panel'));
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
                <a class="stop stop-panel" href="/dashboard" @if (request()->is('dashboard')) aria-current="page" @endif>Panel</a>
                <a class="stop stop-socios" href="/members" @if (request()->is('members*')) aria-current="page" @endif>Socios</a>
                <a class="stop stop-cuotas" href="/fees" @if (request()->is('fees*')) aria-current="page" @endif>Cuotas</a>
                <a class="stop stop-movimientos" href="/bank" @if (request()->is('bank*')) aria-current="page" @endif>Movimientos</a>
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
    <script>
        // Theme switch: follows the system until the person chooses; the choice is remembered in this browser.
        (function () {
            var button = document.getElementById('theme-toggle');
            if (!button) return;
            var root = document.documentElement;
            function current() {
                return root.dataset.theme || (matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark');
            }
            function paint() {
                button.textContent = current() === 'light' ? 'Modo oscuro' : 'Modo claro';
                button.hidden = false;
            }
            button.addEventListener('click', function () {
                var next = current() === 'light' ? 'dark' : 'light';
                root.dataset.theme = next;
                try { localStorage.setItem('nexo-theme', next); } catch (e) {}
                paint();
            });
            paint();
        })();
    </script>
    <footer class="site-footer">
        <p>Demo con datos ficticios · No uses datos personales ni dinero real</p>
    </footer>
</body>
</html>

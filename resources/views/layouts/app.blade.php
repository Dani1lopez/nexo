<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $__env->hasSection('full_title') ? $__env->yieldContent('full_title') : $__env->yieldContent('title').' — Nexo' }}</title>
    @vite('resources/css/app.css')
</head>
<body class="flex min-h-screen flex-col">
    <a class="skip-link" href="#contenido">Saltar al contenido</a>
    <header class="site-header">
        <div class="mx-auto flex max-w-page flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3">
            {{-- The public entry page runs without session, so it must not ask who is signed in. --}}
            @hasSection('public')
                <a class="brand" href="/">Nexo</a>
                <a class="nav-link ml-auto" href="/login">Acceder</a>
            @else
                @auth
                    <a class="brand" href="/dashboard">Nexo</a>
                    <nav aria-label="Principal" class="flex flex-wrap gap-1">
                        <a class="nav-link" href="/dashboard" @if (request()->is('dashboard')) aria-current="page" @endif>Panel</a>
                        <a class="nav-link" href="/members" @if (request()->is('members*')) aria-current="page" @endif>Socios</a>
                        <a class="nav-link" href="/fees" @if (request()->is('fees*')) aria-current="page" @endif>Cuotas</a>
                        <a class="nav-link" href="/bank" @if (request()->is('bank*')) aria-current="page" @endif>Movimientos</a>
                    </nav>
                    <div class="ml-auto flex flex-wrap items-center gap-3 text-sm">
                        <span>{{ auth()->user()->name }} · {{ auth()->user()->role === 'treasury' ? 'Tesorería' : 'Junta' }}</span>
                        <form method="post" action="/logout">@csrf<button type="submit" class="btn-secondary btn-sm">Cerrar sesión</button></form>
                    </div>
                @else
                    <a class="brand" href="/">Nexo</a>
                @endauth
            @endif
        </div>
    </header>
    <main id="contenido" tabindex="-1" class="mx-auto w-full flex-1 px-4 py-6 @hasSection('narrow') max-w-narrow @else max-w-page @endif">
        <div class="panel">
            @yield('content')
        </div>
    </main>
    <footer class="site-footer">
        <p>Demo con datos ficticios · No uses datos personales ni dinero real</p>
    </footer>
</body>
</html>

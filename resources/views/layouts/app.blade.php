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
        {{-- The public entry page runs without session, so it must not ask who is signed in. --}}
        @hasSection('public')
            <a class="brand" href="/">Nexo</a>
            <a class="btn btn-secondary btn-sm ml-auto mb-1" href="/login">Acceder</a>
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
    </header>
    @if (! $__env->hasSection('public'))
        @auth
            <nav aria-label="Principal" class="tabs">
                <a class="tab" href="/dashboard" @if (request()->is('dashboard')) aria-current="page" @endif>Panel</a>
                <a class="tab" href="/members" @if (request()->is('members*')) aria-current="page" @endif>Socios</a>
                <a class="tab" href="/fees" @if (request()->is('fees*')) aria-current="page" @endif>Cuotas</a>
                <a class="tab" href="/bank" @if (request()->is('bank*')) aria-current="page" @endif>Movimientos</a>
            </nav>
        @endauth
    @endif
    <main id="contenido" tabindex="-1" class="mx-auto w-full flex-1 px-4 pb-6 @if ($__env->hasSection('public') || ! auth()->check()) pt-6 @endif @hasSection('narrow') max-w-narrow @else max-w-page @endif">
        <div class="sheet">
            @yield('content')
        </div>
    </main>
    <footer class="site-footer">
        <p>Demo con datos ficticios · No uses datos personales ni dinero real</p>
    </footer>
</body>
</html>

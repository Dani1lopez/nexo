<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') — Nexo</title>
    <link rel="stylesheet" href="/css/access.css">
</head>
<body>
    <main class="card">
        <a href="/">Nexo</a>
        <p class="notice">Demo con datos ficticios · No uses datos personales ni dinero real</p>
        @yield('content')
    </main>
</body>
</html>

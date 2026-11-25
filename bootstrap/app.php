<?php

use App\Http\Middleware\SecurityHeaders;
use App\Modules\Demo\Http\Middleware\OpenDemoSession;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            // This public, read-only entry page needs no session or database.
            Route::view('/', 'home')->name('home');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Laravel's web group supplies sessions and request-forgery protection.
        // Prepended so every response carries the browser hardening headers, including
        // early rejections from the default stack such as a maintenance-mode 503.
        $middleware->prepend(SecurityHeaders::class);

        // Issue #74: a session marked as demo is served inside its own per-request sandbox.
        // It has to run before `auth`, the route model bindings and every `can:` of the real
        // routes, so the guard and the bindings read the throwaway database and not the treasury
        // one; the priority list is what places it there. It opens nothing for a session without
        // the mark, for a real identity and for every route the demo does not serve.
        $middleware->appendToGroup('web', OpenDemoSession::class);
        $middleware->prependToPriorityList(AuthenticatesRequests::class, OpenDemoSession::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Use the framework's default exception handling.
    })->create();

<?php

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
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Use the framework's default exception handling.
    })->create();

<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // We have no email/password login; Discord OAuth is the only way
        // in. Redirect unauthenticated requests there instead of the
        // Laravel default 'login' named route (which doesn't exist).
        $middleware->redirectGuestsTo(fn () => route('auth.discord.start'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();

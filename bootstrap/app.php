<?php

use App\Support\Tenant;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
            'tenant.resolve' => \App\Http\Middleware\ResolveTenant::class,
        ]);

        // There's no single shared /login anymore (every shop has its own,
        // plus /superadmin/login) — route('login') can't resolve without a
        // shop, so a guest hitting a protected route needs this instead.
        $middleware->redirectGuestsTo(fn (Request $request) => Tenant::loginUrlFor($request));

        // Global so route('tenant.*', ...) also resolves on POST /livewire/update —
        // the shared endpoint every Livewire action hits, outside /s/{shop}/*.
        $middleware->web(append: [
            \App\Http\Middleware\ShareTenantUrlDefault::class,
            \App\Http\Middleware\EnsureUserIsActive::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();

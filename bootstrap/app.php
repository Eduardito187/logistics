<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Deep-link del admin a un registro inexistente (un UUID viejo que quedó en una pestaña, un
        // marcador o el "intended" al re-loguear): en vez de dejar al usuario en un 404 sin salida, lo
        // devolvemos al listado de ese recurso. Así NINGUNA URL del panel se rompe: cualquier enlace
        // viejo aterriza en una página viva.
        //
        // Se cubren los dos tipos: ModelNotFoundException (Filament no resolvió el registro por su
        // route-binding) y NotFoundHttpException (ruta de admin inexistente). Misma redirección.
        $adminNotFoundRedirect = static function (Request $request): ?Response {
            if (!$request->isMethod('GET') || !$request->is('admin/*') || !Auth::check()) {
                return null;
            }

            $segments = $request->segments(); // ['admin', '<recurso>', '<id>', ...]
            $target = count($segments) >= 2 ? '/'.$segments[0].'/'.$segments[1] : '/admin';

            // Evita bucles: solo redirige si el destino (el listado) es distinto de la ruta actual.
            if (trim($request->getPathInfo(), '/') === trim($target, '/')) {
                return null;
            }

            // RedirectResponse directo (no el helper redirect(), que Livewire intercepta y devuelve su
            // propio Redirector —no un Response— rompiendo el tipo de retorno en peticiones Livewire).
            return new RedirectResponse(url($target));
        };

        $exceptions->render(fn (ModelNotFoundException $e, Request $request): ?Response => $adminNotFoundRedirect($request));
        $exceptions->render(fn (NotFoundHttpException $e, Request $request): ?Response => $adminNotFoundRedirect($request));
    })->create();

<?php

declare(strict_types=1);

use App\Http\Middleware\RequestId;
use App\Http\Problem\ProblemExceptionRenderer;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
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
    // Canales de broadcasting en vivo (telemetría): registra /broadcasting/auth (sesión web del SPA)
    // y carga la autorización de canales de routes/channels.php (fleet/vehicle/shipment).
    ->withBroadcasting(__DIR__.'/../routes/channels.php')
    ->withMiddleware(function (Middleware $middleware): void {
        // M-7: request-id de correlación lo más temprano posible (antes que cualquier otro middleware),
        // para que X-Request-Id ya esté en el Context y en los logs del resto de la cadena del request.
        $middleware->prepend(RequestId::class);

        $middleware->web(append: [
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        // Invitado en una ruta web protegida → al login del panel admin (único frontend).
        $middleware->redirectGuestsTo(static fn (): string => route('filament.admin.auth.login'));
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

        // Manejo de errores UNIFORME (RFC 7807, application/problem+json) para las tres puertas de la
        // API. Se registra DESPUÉS de los handlers del admin: para una petición api/* aquellos devuelven
        // null (no son admin) y este toma el relevo; para el admin (web/Livewire) este devuelve null y
        // conserva el manejo HTML y la redirección de deep-links de arriba. El catálogo de códigos vive
        // en App\Http\Problem\ErrorCode.
        $problemRenderer = new ProblemExceptionRenderer;
        $exceptions->render(fn (Throwable $e, Request $request): ?Response => $problemRenderer->render($e, $request));
    })->create();

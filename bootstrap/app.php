<?php

declare(strict_types=1);

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnsureAppVersion;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RecordRequestMetrics;
use App\Http\Problem\ProblemExceptionRenderer;
use App\Observability\Heartbeat;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
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
        // Ola 5 — métricas RED del tráfico HTTP (contador + histograma de duración en Valkey). Global y no
        // por grupo web/api a propósito: así también cuenta los 404 de URLs sin ruta (`route="unmatched"`),
        // que nunca entran a un grupo. Terminable: escribe DESPUÉS de enviar la respuesta. Se antepone (y no
        // se agrega al final) para medir la pila completa; no altera el orden relativo del resto.
        $middleware->prepend(RecordRequestMetrics::class);

        // M-7 / Ola 5: request-id de correlación lo más temprano posible (antes que cualquier otro
        // middleware, por eso se antepone ÚLTIMO), para que X-Request-Id ya esté en el Context y en los
        // logs del resto de la cadena del request. Toma el header entrante o genera un UUID v7.
        $middleware->prepend(AssignRequestId::class);

        // Versión mínima de la app interna: cabecera X-Min-App-Version en toda respuesta /api/v1/app/* y
        // 426 Upgrade Required si X-App-Version está por debajo. Global (se autolimita a la puerta app)
        // para cubrir también el login público y los 404 de la puerta. Sin mínima configurada es no-op.
        $middleware->append(EnsureAppVersion::class);

        // Detrás de un proxy/túnel (nginx, Cloudflare, cloudflared/ngrok para acceso móvil): confiar en
        // las cabeceras X-Forwarded-* para detectar el HOST y el ESQUEMA (HTTPS) reales. Sin esto, tras un
        // túnel HTTPS el framework ve "http" y genera assets/redirecciones http → "mixed content" y la
        // página queda rota. Ingreso único por nginx; en producción se puede acotar `at` a los rangos de
        // Cloudflare en vez de '*'.
        $middleware->trustProxies(at: '*', headers: Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_HOST
            | Request::HEADER_X_FORWARDED_PORT
            | Request::HEADER_X_FORWARDED_PROTO);

        $middleware->web(append: [
            AddLinkHeadersForPreloadedAssets::class,
            // App Vue+Inertia (superficie cliente/campo), separada del admin Filament.
            HandleInertiaRequests::class,
        ]);

        // Invitado en una ruta web protegida: si es la app Vue+Inertia (/app) → su propio login de chofer;
        // si no, al login del panel admin Filament.
        $middleware->redirectGuestsTo(static fn (Request $request): string => $request->is('app', 'app/*')
            ? '/app/login'
            : route('filament.admin.auth.login'));
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
    })
    // Ola 5 — latido de los workers de cola: el worker dispara `Looping` en cada vuelta de su bucle (con
    // o sin trabajos), y Heartbeat deja el timestamp en Valkey (limitado a uno cada pocos segundos por
    // proceso). Se escucha el evento en vez de usar `Queue::looping()` para no resolver el QueueManager
    // (provider diferido) en cada request HTTP: registrar el listener no cuesta nada si nadie lo dispara.
    ->booted(static function (): void {
        Event::listen(Looping::class, static function (Looping $event): void {
            Heartbeat::worker($event->queue);
        });
    })->create();

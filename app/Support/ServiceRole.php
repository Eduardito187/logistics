<?php

declare(strict_types=1);

namespace App\Support;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Rol de despliegue del proceso (config/service.php, env APP_SERVICE_ROLE).
 *
 * Permite que la misma imagen del monolito modular corra como unidad acotada a un bounded context
 * (p. ej. `telemetry`): el proceso sigue registrando todos los providers y bindings, pero solo atiende
 * las rutas HTTP de los contextos de su rol (más las sondas de vida y métricas).
 *
 * Tres usos:
 *  - Consulta: `ServiceRole::serves('telemetry')` para que un contexto decida si monta SUS rutas, y
 *    `ServiceRole::allowsPath()` para saber si una ruta le corresponde al rol.
 *  - Activación: `ServiceRole::enforce($app)` desde un ServiceProvider. Con `monolith` no hace nada (el
 *    proceso queda exactamente igual); con un rol acotado antepone esta clase como middleware global.
 *  - Middleware: responde 404 a toda ruta que el rol no sirve, antes de autenticar o tocar la base.
 *
 * Se filtra por ruta de la petición (O(1) por request y compatible con `route:cache`) en vez de podar
 * el router: el resultado para el cliente es el mismo, sin costo de arranque.
 */
final class ServiceRole
{
    public const MONOLITH = 'monolith';

    /** Rol configurado del proceso; un rol desconocido o vacío equivale a `monolith`. */
    public static function current(): string
    {
        $role = strtolower(trim((string) config('service.role', self::MONOLITH)));

        if ($role === '' || $role === self::MONOLITH) {
            return self::MONOLITH;
        }

        return is_array(config('service.roles.'.$role)) ? $role : self::MONOLITH;
    }

    public static function isMonolith(): bool
    {
        return self::current() === self::MONOLITH;
    }

    /** ¿Este proceso sirve las rutas HTTP del contexto dado? El monolito sirve todos. */
    public static function serves(string $context): bool
    {
        $role = self::current();
        if ($role === self::MONOLITH) {
            return true;
        }

        /** @var list<string> $contexts */
        $contexts = (array) config('service.roles.'.$role.'.contexts', []);

        return in_array(strtolower($context), $contexts, true);
    }

    /**
     * ¿El rol atiende esta ruta? `$path` va sin barra inicial (como Request::path()).
     */
    public static function allowsPath(string $path): bool
    {
        $role = self::current();
        if ($role === self::MONOLITH) {
            return true;
        }

        /** @var list<string> $patterns */
        $patterns = [
            ...(array) config('service.always', []),
            ...(array) config('service.roles.'.$role.'.paths', []),
        ];

        return Str::is($patterns, trim($path, '/'));
    }

    /**
     * Activa el filtro de rutas del rol. Idempotente; con `monolith` es un no-op.
     */
    public static function enforce(Application $app): void
    {
        if (self::isMonolith()) {
            return;
        }

        $kernel = $app->make(HttpKernelContract::class);
        if ($kernel instanceof HttpKernel && !$kernel->hasMiddleware(self::class)) {
            $kernel->prependMiddleware(self::class);
        }
    }

    /**
     * Middleware global del rol acotado: lo que el rol no sirve responde 404.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (!self::allowsPath($request->path())) {
            throw new NotFoundHttpException('Not served by this service role.');
        }

        return $next($request);
    }
}

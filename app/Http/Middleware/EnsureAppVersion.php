<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Problem\ErrorCode;
use App\Http\Problem\ProblemDetails;
use App\Http\Support\AppClientConfig;
use App\Http\Support\AppVersion;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Compatibilidad de versión de la app interna en la puerta `/api/v1/app/*` (pedido de la app
 * 2026-10-16 «versión mínima» y auditoría 2026-11-12).
 *
 * - Lee la versión instalada de `X-App-Version` (y la plataforma de `X-App-Platform`, opcional: sin ella
 *   se usa la mínima `default`).
 * - Si hay mínima configurada y la versión es MENOR, responde `426 Upgrade Required` (problem+json,
 *   `code=upgrade_required`) con `min_app_version`, `installed_version` y `update_url`.
 * - Requests SIN `X-App-Version` (o con un valor ilegible) pasan siempre: compatibilidad hacia atrás con
 *   las versiones que todavía no mandan la cabecera.
 * - En TODA respuesta `/app/*` (2xx y errores) agrega `X-Min-App-Version` cuando hay mínima, que la app
 *   ya lee para bloquearse sola.
 * - `GET /app/app-config` nunca se rechaza: la app vieja la necesita para saber a dónde actualizarse.
 *
 * Se registra como middleware GLOBAL (bootstrap/app.php) y se autolimita por ruta: así cubre también el
 * login público y los 404 de la puerta, que no pasan por el grupo de rutas.
 */
final class EnsureAppVersion
{
    public const string VERSION_HEADER = 'X-App-Version';

    public const string BUILD_HEADER = 'X-App-Build';

    public const string PLATFORM_HEADER = 'X-App-Platform';

    public const string MIN_VERSION_HEADER = 'X-Min-App-Version';

    public function __construct(private readonly AppClientConfig $config) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->is('api/v1/app', 'api/v1/app/*')) {
            return $next($request);
        }

        $platform = AppClientConfig::normalizePlatform($request->header(self::PLATFORM_HEADER));
        $minimum = $this->config->minVersion($platform);

        if ($minimum !== null && $this->mustUpgrade($request, $minimum)) {
            return $this->withMinHeader($this->upgradeRequired($request, $minimum, $platform), $minimum);
        }

        $response = $next($request);

        return $minimum === null ? $response : $this->withMinHeader($response, $minimum);
    }

    private function mustUpgrade(Request $request, string $minimum): bool
    {
        if (!$this->config->enforceMinVersion() || $request->is('api/v1/app/app-config')) {
            return false;
        }

        $header = $request->header(self::VERSION_HEADER);
        $installed = AppVersion::normalize(is_string($header) ? $header : null);

        return $installed !== null && AppVersion::isBelow($installed, $minimum);
    }

    private function upgradeRequired(Request $request, string $minimum, ?string $platform): Response
    {
        $header = $request->header(self::VERSION_HEADER);

        return ProblemDetails::fromCode(
            ErrorCode::UpgradeRequired,
            "This app version is no longer supported. Update to {$minimum} or later.",
        )
            ->withExtension('min_app_version', $minimum)
            ->withExtension('installed_version', AppVersion::normalize(is_string($header) ? $header : null))
            ->withExtension('update_url', $this->config->storeUrl($platform))
            ->toResponse($request);
    }

    private function withMinHeader(Response $response, string $minimum): Response
    {
        $response->headers->set(self::MIN_VERSION_HEADER, $minimum);

        return $response;
    }
}

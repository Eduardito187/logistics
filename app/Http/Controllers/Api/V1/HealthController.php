<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Support\Health\HealthProbe;
use Illuminate\Http\JsonResponse;

/**
 * Salud de la API para la app, balanceadores y monitoreo.
 *
 * A diferencia de /up (solo "el proceso responde"), revisa las dependencias críticas vía HealthProbe:
 * PostgreSQL con PostGIS, una tabla real del esquema (detecta roturas de esquema/plan) y Valkey.
 * Responde 503 si alguna falla. Es la sonda de READINESS (rotación/deploy); la de LIVENESS es el
 * comando `health:check`, que usa el MISMO HealthProbe.
 */
final class HealthController
{
    public function __construct(
        private readonly HealthProbe $probe,
    ) {}

    public function __invoke(): JsonResponse
    {
        $checks = $this->probe->checks();
        $healthy = HealthProbe::isHealthy($checks);

        // Sonda de vida: jamás debe cachearse. Un balanceador/proxy que sirva un
        // "ok" viejo enmascararía una dependencia caída (PostgreSQL/Valkey).
        return new JsonResponse([
            'status' => $healthy ? 'ok' : 'degraded',
            'api_version' => 'v1',
            'checks' => $checks,
        ], $healthy ? 200 : 503, [
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }
}

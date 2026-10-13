<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Salud de la API para la app, balanceadores y monitoreo.
 *
 * A diferencia de /up (solo "el proceso responde"), revisa las dependencias críticas:
 * PostgreSQL con PostGIS y Valkey. Responde 503 si alguna falla.
 */
final class HealthController
{
    public function __invoke(): JsonResponse
    {
        $checks = [
            'database' => $this->check(fn (): bool => DB::selectOne('select postgis_version() as v') !== null),
            'cache' => $this->check(fn (): bool => (bool) Redis::connection()->ping()),
        ];
        $healthy = !in_array(false, $checks, true);

        return new JsonResponse([
            'status' => $healthy ? 'ok' : 'degraded',
            'api_version' => 'v1',
            'checks' => $checks,
        ], $healthy ? 200 : 503);
    }

    /**
     * @param callable(): bool $probe
     */
    private function check(callable $probe): bool
    {
        try {
            return $probe();
        } catch (Throwable) {
            return false;
        }
    }
}

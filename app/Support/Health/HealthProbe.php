<?php

declare(strict_types=1);

namespace App\Support\Health;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Sondas de salud compartidas por el endpoint `/api/v1/health` (READINESS: lo miran los balanceadores
 * y el smoke test del deploy) y el comando `health:check` (LIVENESS: lo usa el healthcheck del
 * contenedor). Un único lugar para que ambos midan EXACTAMENTE lo mismo y el health no mienta.
 */
final class HealthProbe
{
    /**
     * Ejecuta todas las sondas y devuelve el mapa nombre→ok. Cada sonda es tolerante: si lanza, cuenta
     * como caída (false), nunca propaga.
     *
     * @return array<string, bool>
     */
    public function checks(): array
    {
        return [
            // PostGIS disponible (la extensión que usan ruteo/geocercas). Confirma que PG responde.
            'database' => $this->probe(fn (): bool => DB::selectOne('select postgis_version() as v') !== null),
            // Ejercita una TABLA REAL del esquema: detecta roturas de esquema o de plan preparado —p. ej.
            // el `cached plan must not change result type` (0A000) de PgBouncer tras una migración— que
            // `postgis_version()` NO tocaría. Barato: `limit 1`, sin scan. Cuenta como OK si la consulta
            // CORRE, no si devuelve filas: una tabla vacía es sana.
            'schema' => $this->probe(static function (): bool {
                DB::selectOne('select 1 from users limit 1');

                return true;
            }),
            // Valkey alcanzable (cache + colas + sesiones + locks).
            'cache' => $this->probe(fn (): bool => (bool) Redis::connection()->ping()),
        ];
    }

    /**
     * Sano = ninguna sonda en false.
     *
     * @param array<string, bool> $checks
     */
    public static function isHealthy(array $checks): bool
    {
        return !in_array(false, $checks, true);
    }

    /**
     * @param callable(): bool $probe
     */
    private function probe(callable $probe): bool
    {
        try {
            return $probe();
        } catch (Throwable) {
            return false;
        }
    }
}

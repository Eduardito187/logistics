<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Health\HealthProbe;
use Illuminate\Console\Command;

/**
 * Sonda de LIVENESS para el healthcheck del contenedor: corre el MISMO HealthProbe que
 * `/api/v1/health` y sale con código 0 (sano) o 1 (alguna dependencia caída). No habla HTTP (el
 * contenedor `app` es FPM, no sirve HTTP), así evita depender de cgi-fcgi.
 *
 * El contenedor se marca `unhealthy` solo tras varios fallos SOSTENIDOS (umbral del healthcheck:
 * interval×retries), y recién ahí el sidecar `autoheal` lo reinicia. Es defensa EN PROFUNDIDAD: la
 * defensa principal es que la falla no ocurra (emulate-prepares contra PgBouncer).
 */
final class HealthCheckCommand extends Command
{
    protected $signature = 'health:check';

    protected $description = 'Sonda de liveness: verifica PostgreSQL/PostGIS, esquema y Valkey (exit 0/1).';

    public function handle(HealthProbe $probe): int
    {
        $checks = $probe->checks();

        foreach ($checks as $name => $ok) {
            if (!$ok) {
                $this->error("health: {$name} FAILED");
            }
        }

        return HealthProbe::isHealthy($checks) ? self::SUCCESS : self::FAILURE;
    }
}

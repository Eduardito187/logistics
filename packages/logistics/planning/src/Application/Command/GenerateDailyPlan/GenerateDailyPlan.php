<?php

declare(strict_types=1);

namespace Logistics\Planning\Application\Command\GenerateDailyPlan;

use Logistics\Shared\Application\Command\Command;

/**
 * Dispara la planificación AUTOMÁTICA del día: arma camiones + rutas + plan de carga a partir de los
 * pedidos pendientes, según el modo elegido. No hace el cálculo pesado: deja una corrida encolada que
 * procesa un worker (asíncrono, por lotes, idempotente).
 *
 * - planDate: 'Y-m-d'.
 * - strategy: 'optimization' (cercanía + capacidad) | 'slots' (respeta la franja del cliente).
 * - params: overrides puntuales de los parámetros configurables (hora de salida, holgura, etc.); lo
 *   que no venga se toma de la configuración versionada (settings).
 */
final readonly class GenerateDailyPlan implements Command
{
    /**
     * @param array<string, mixed> $params
     */
    public function __construct(
        public string $planDate,
        public string $strategy,
        public array $params = [],
    ) {}
}

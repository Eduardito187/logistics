<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Infrastructure\Console;

use Illuminate\Console\Command;
use Logistics\FleetOps\Application\Command\AlertDueMaintenance\AlertDueMaintenance;
use Logistics\FleetOps\Application\Command\AlertDueMaintenance\AlertDueMaintenanceHandler;

/**
 * Barrido programado de mantenimiento preventivo por vencer/vencido. Lo corre el scheduler (diario) o se
 * invoca a mano. Delega en el handler, que emite la alerta al outbox. Imprime el resumen de la corrida.
 *
 * Texto visible en inglés (regla de consola); comentarios en español.
 */
final class AlertDueMaintenanceCommand extends Command
{
    protected $signature = 'logistics:fleetops:maintenance-alerts';

    protected $description = 'Scan preventive maintenance plans for upcoming/overdue service and alert the supervisor via the outbox';

    public function handle(AlertDueMaintenanceHandler $handler): int
    {
        $handler->handle(new AlertDueMaintenance);

        $this->info(sprintf(
            'Maintenance due scan finished: %d due (%d overdue, %d upcoming); alert %s.',
            $handler->lastTotal(),
            $handler->lastOverdue(),
            $handler->lastDueSoon(),
            $handler->lastEmitted() ? 'emitted' : 'skipped (nothing to report)',
        ));

        return self::SUCCESS;
    }
}

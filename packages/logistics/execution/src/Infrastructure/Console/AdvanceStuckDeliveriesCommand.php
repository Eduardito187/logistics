<?php

declare(strict_types=1);

namespace Logistics\Execution\Infrastructure\Console;

use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;

/**
 * Backfill de CONSISTENCIA: avanza a LOADED (Cargada) las entregas que quedaron en PENDING antes de que
 * el flujo vigente abriera las entregas directamente en LOADED (asignar vehículo = cargada). Una entrega
 * en PENDING ya tiene vehículo asignado y, por el guard relajado, es entregable; este comando solo corrige
 * el estado visible para que la UI no muestre "pendiente" en pedidos ya asignados.
 *
 * Seguro: solo toca filas en PENDING (nunca terminales ni ya cargadas). Idempotente: una segunda corrida no
 * encuentra nada que cambiar. UPDATE directo (no pasa por el agregado: no regenera escaneos ni inunda el
 * outbox con hechos históricos), igual criterio que `shipments:sync-status`. NO avanza el estado del ENVÍO
 * (eso lo hace `shipments:sync-status`, que mapea una entrega PENDING/LOADED → IN_EXECUTION).
 */
final class AdvanceStuckDeliveriesCommand extends Command
{
    protected $signature = 'logistics:execution:advance-stuck-deliveries
        {--dry-run : Solo informa cuántas entregas avanzaría, sin escribir}';

    protected $description = 'Avanza a LOADED las entregas atascadas en PENDING (consistencia de UI; idempotente).';

    public function handle(ConnectionInterface $connection): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $pending = (int) $connection->table('execution_deliveries')
            ->where('status', 'PENDING')
            ->count();

        if ($pending === 0) {
            $this->info('No hay entregas en PENDING: nada que avanzar.');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->info("Dry-run: avanzaría {$pending} entrega(s) de PENDING a LOADED.");

            return self::SUCCESS;
        }

        $updated = $connection->table('execution_deliveries')
            ->where('status', 'PENDING')
            ->update(['status' => 'LOADED', 'updated_at' => now()]);

        $this->info("Avanzadas {$updated} entrega(s) de PENDING a LOADED.");

        return self::SUCCESS;
    }
}

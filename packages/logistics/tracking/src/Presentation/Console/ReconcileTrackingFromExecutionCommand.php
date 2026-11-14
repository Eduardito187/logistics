<?php

declare(strict_types=1);

namespace Logistics\Tracking\Presentation\Console;

use Illuminate\Console\Command;
use Illuminate\Database\ConnectionResolverInterface;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Tracking\Application\Command\ProjectExecutionEvent\ProjectExecutionEvent;
use stdClass;
use Throwable;

/**
 * RECUPERA el estado visible del seguimiento desde el estado REAL de las entregas de execution.
 *
 * Por qué existe: un `execution.shipment_delivered` que se despachó del outbox contra una versión vieja del
 * read model (cuando la guardia de `VisibleStatus` no admitía Loaded→Delivered) se DESCARTÓ en silencio y el
 * envío quedó atascado en «Cargada». Como el mensaje del outbox ya quedó marcado Despachado, NO se vuelve a
 * disparar solo: hay que reproyectar a mano desde la fuente de verdad (la tabla `execution_deliveries`).
 *
 * Qué hace: por cada entrega DELIVERED/FAILED, re-despacha `ProjectExecutionEvent` (el mismo puente que usa el
 * relay) para que la proyección de seguimiento se ponga al día. Idempotente: si el seguimiento YA está en ese
 * estado, el handler es no-op (no reemite). Tolerante: una fila mala no corta el lote. Dry-run por defecto.
 *
 * Lee la tabla de OTRO contexto (execution_deliveries) por nombre: es una herramienta de recuperación
 * (Infrastructure de consola), no dominio; por eso no acopla clases de execution, solo el nombre de la tabla.
 */
final class ReconcileTrackingFromExecutionCommand extends Command
{
    protected $signature = 'logistics:tracking:reconcile-from-execution
        {--force : Aplica los cambios (sin esto es simulación)}
        {--chunk=500 : Tamaño de lote}
        {--shipment=* : Limitar a estos ids de envío}';

    protected $description = 'Reproyecta el seguimiento desde las entregas DELIVERED/FAILED de execution (recupera los atascados en «Cargada»)';

    public function handle(CommandBus $bus, ConnectionResolverInterface $connections): int
    {
        $force = $this->option('force') === true;
        $chunk = max(1, (int) $this->option('chunk'));
        /** @var list<string> $shipments */
        $shipments = array_values(array_filter((array) $this->option('shipment'), 'is_string'));

        $total = 0;
        $dispatched = 0;
        $skipped = 0;

        try {
            $query = $connections->connection()
                ->table('execution_deliveries')
                ->whereIn('status', ['DELIVERED', 'FAILED'])
                ->when($shipments !== [], static fn ($q) => $q->whereIn('shipment_id', $shipments))
                ->orderBy('id');
        } catch (Throwable $e) {
            $this->error('No se pudo leer execution_deliveries: '.$e->getMessage());

            return self::FAILURE;
        }

        $query->chunkById($chunk, function (iterable $rows) use ($bus, $force, &$total, &$dispatched, &$skipped): void {
            foreach ($rows as $row) {
                if (!$row instanceof stdClass) {
                    continue;
                }
                $total++;

                $shipmentId = is_string($row->shipment_id ?? null) ? $row->shipment_id : '';
                if ($shipmentId === '') {
                    continue;
                }
                $type = ((string) ($row->status ?? '')) === 'DELIVERED'
                    ? 'execution.shipment_delivered'
                    : 'execution.delivery_failed';
                $occurredAt = is_string($row->pod_captured_at ?? null) ? $row->pod_captured_at : null;

                if (!$force) {
                    continue;
                }

                try {
                    $bus->dispatch(new ProjectExecutionEvent($type, $shipmentId, $occurredAt));
                    $dispatched++;
                } catch (Throwable $e) {
                    $skipped++;
                    $this->warn("envío {$shipmentId}: {$e->getMessage()}");
                }
            }
        });

        if (!$force) {
            $this->info("Simulación: {$total} entregas DELIVERED/FAILED se reproyectarían. Usá --force para aplicar.");

            return self::SUCCESS;
        }

        $this->info("Listo. Entregas revisadas: {$total}. Reproyectadas: {$dispatched}. Saltadas: {$skipped}.");

        return self::SUCCESS;
    }
}

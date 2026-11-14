<?php

declare(strict_types=1);

namespace Logistics\Tracking\Presentation\Console;

use Illuminate\Console\Command;
use Illuminate\Database\ConnectionResolverInterface;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Tracking\Application\Command\ProjectExecutionEvent\ProjectExecutionEvent;
use Logistics\Tracking\Application\Command\SyncRouteStops\SyncRouteStops;
use Logistics\Tracking\Application\Contract\RouteStopsReader;
use Logistics\Tracking\Application\Service\EnsureShipmentTracking;
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
 * Además deja el seguimiento COMPLETO para los envíos vivos (antes solo se veía al entregar):
 *  - abre el seguimiento de cada envío que no lo tiene (entró por ingesta o se perdieron sus eventos);
 *  - ubica cada envío en la ruta real de su camión y cuenta sus paradas pendientes por delante.
 * Esos dos pasos NO graban eventos (no reemiten avisos al cliente por algo que ya pasó).
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

    public function handle(
        CommandBus $bus,
        ConnectionResolverInterface $connections,
        RouteStopsReader $routes,
        EnsureShipmentTracking $ensureTracking,
    ): int {
        $force = $this->option('force') === true;
        $chunk = max(1, (int) $this->option('chunk'));
        /** @var list<string> $shipments */
        $shipments = array_values(array_filter((array) $this->option('shipment'), 'is_string'));

        if ($shipments === []) {
            $this->openMissingTrackings($routes, $ensureTracking, $force, $chunk);
        }

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

        if ($shipments === []) {
            $this->syncRoutes($bus, $routes, $force);
        }

        if (!$force) {
            $this->info("Simulación: {$total} entregas DELIVERED/FAILED se reproyectarían. Usá --force para aplicar.");

            return self::SUCCESS;
        }

        $this->info("Listo. Entregas revisadas: {$total}. Reproyectadas: {$dispatched}. Saltadas: {$skipped}.");

        return self::SUCCESS;
    }

    /**
     * Abre el seguimiento de los envíos que no lo tienen. Por lotes; corta si un lote no logra abrir
     * ninguno (envíos sin referencia de orden) para no girar en falso.
     */
    private function openMissingTrackings(RouteStopsReader $routes, EnsureShipmentTracking $ensureTracking, bool $force, int $chunk): void
    {
        try {
            if (!$force) {
                $pending = count($routes->shipmentsWithoutTracking(100000));
                $this->info("Simulación: {$pending} envíos sin seguimiento se abrirían.");

                return;
            }

            $opened = 0;
            $unopenable = [];
            do {
                $openedInBatch = 0;
                foreach ($routes->shipmentsWithoutTracking($chunk + count($unopenable)) as $shipmentId) {
                    if (isset($unopenable[$shipmentId])) {
                        continue;
                    }
                    if ($ensureTracking->forShipment($shipmentId, true) !== null) {
                        $openedInBatch++;
                    } else {
                        $unopenable[$shipmentId] = true;
                    }
                }
                $opened += $openedInBatch;
            } while ($openedInBatch > 0);

            $this->info("Seguimientos abiertos: {$opened}. Sin referencia de orden (no se pueden abrir): ".count($unopenable).'.');
        } catch (Throwable $e) {
            $this->warn('No se pudieron abrir los seguimientos faltantes: '.$e->getMessage());
        }
    }

    /**
     * Ubica en su ruta a los envíos de cada ruta con paradas pendientes (posición + paradas por delante).
     */
    private function syncRoutes(CommandBus $bus, RouteStopsReader $routes, bool $force): void
    {
        try {
            $routeIds = $routes->routesWithPendingStops();
        } catch (Throwable $e) {
            $this->warn('No se pudieron leer las rutas: '.$e->getMessage());

            return;
        }

        if (!$force) {
            $this->info('Simulación: '.count($routeIds).' rutas con paradas pendientes se alinearían.');

            return;
        }

        $synced = 0;
        foreach ($routeIds as $routeId) {
            try {
                $bus->dispatch(new SyncRouteStops($routeId, null, true));
                $synced++;
            } catch (Throwable $e) {
                $this->warn("ruta {$routeId}: {$e->getMessage()}");
            }
        }

        $this->info("Rutas alineadas: {$synced}.");
    }
}

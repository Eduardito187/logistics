<?php

declare(strict_types=1);

namespace Logistics\Shipments\Infrastructure\Console;

use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Logistics\Shipments\Domain\ValueObject\OrderSource;
use Logistics\Shipments\Domain\ValueObject\ShipmentStatus;
use Logistics\Shipments\Domain\ValueObject\SourceReference;
use Throwable;

/**
 * Reparación (backfill) de los envíos que quedaron atrás porque, hasta ahora, nada movía `shipments.status`
 * ni poblaba `shipments.destination_node_id` en los envíos de retiro.
 *
 * Fase `status`: recorre por keyset (id UUID v7) los envíos en RECEIVED/PLANNED y deriva su estado real de
 * las tablas de otros contextos (lectura cruzada de SOLO LECTURA):
 *  - execution_deliveries.status DELIVERED                                       → DELIVERED
 *  - execution_deliveries.status PENDING / PREPARED / LOADED / IN_TRANSIT / FAILED → IN_EXECUTION
 *    (PENDING ya trae el camión asignado: asignar vehículo = preparar = en ejecución, flujo vigente)
 *  - execution_driver_route_stops o una parada de un plan PUBLICADO (sin entrega aún)              → PLANNED
 * Solo AVANZA (el UPDATE lleva la guarda del estado de partida: nunca retrocede ni pisa un estado cerrado).
 *
 * Fase `nodes`: completa el nodo destino de los envíos hermanos de retiro cuyo id externo trae el sufijo
 * `#n:{uuid}` (misma regla que el ingreso, SourceReference::pickupNodeId), solo si ese nodo existe en la red
 * y la columna está vacía (COALESCE de facto: nunca pisa un nodo ya puesto).
 *
 * Idempotente: una segunda corrida no encuentra nada que cambiar. Es un adaptador de infraestructura (SQL
 * por tramos, pensado para millones de filas): no pasa por el agregado para no regenerar líneas/bultos ni
 * inundar el outbox con hechos históricos. Los cambios nuevos los hace el reactor
 * ShipmentStatusProgressionDispatcher, por el agregado.
 */
final class SyncShipmentStatusCommand extends Command
{
    protected $signature = 'shipments:sync-status
        {--only= : Limitar a una fase: status | nodes (por defecto, ambas)}
        {--chunk=500 : Envíos por tramo (1..5000)}
        {--dry-run : Solo informa lo que cambiaría, sin escribir}';

    protected $description = 'Avanza el estado de los envíos atascados en RECEIVED/PLANNED según ejecución/planificación y completa el nodo destino de los envíos de retiro.';

    private const TABLE = 'shipments';

    /**
     * Estado de la entrega en ejecución → estado del envío al que corresponde. Flujo vigente: una entrega
     * PENDING ya tiene el camión asignado (asignar vehículo = preparar), así que el envío está IN_EXECUTION,
     * no PLANNED. (Antes PENDING → PLANNED, cuando preparar era un escaneo aparte, ya eliminado.)
     */
    private const DELIVERY_TARGETS = [
        'PENDING' => ShipmentStatus::InExecution,
        'PREPARED' => ShipmentStatus::InExecution,
        'LOADED' => ShipmentStatus::InExecution,
        'IN_TRANSIT' => ShipmentStatus::InExecution,
        'FAILED' => ShipmentStatus::InExecution,
        'DELIVERED' => ShipmentStatus::Delivered,
    ];

    public function handle(ConnectionInterface $connection): int
    {
        $only = $this->option('only');
        $only = is_string($only) && $only !== '' ? strtolower($only) : null;
        if ($only !== null && !in_array($only, ['status', 'nodes'], true)) {
            $this->error('La opción --only acepta: status | nodes');

            return self::INVALID;
        }

        $chunkOption = $this->option('chunk');
        $chunk = is_numeric($chunkOption) ? (int) $chunkOption : 500;
        $chunk = max(1, min($chunk, 5000));
        $dryRun = (bool) $this->option('dry-run');

        if ($only === null || $only === 'status') {
            $counts = $this->syncStatuses($connection, $chunk, $dryRun);
            $verb = $dryRun ? 'Cambiarían' : 'Actualizados';
            $this->info(sprintf(
                '%s a PLANNED: %d · a IN_EXECUTION: %d · a DELIVERED: %d',
                $verb,
                $counts[ShipmentStatus::Planned->value],
                $counts[ShipmentStatus::InExecution->value],
                $counts[ShipmentStatus::Delivered->value],
            ));
        }

        if ($only === null || $only === 'nodes') {
            $nodes = $this->syncDestinationNodes($connection, $chunk, $dryRun);
            $this->info(($dryRun ? 'Envíos de retiro que recibirían nodo destino: ' : 'Envíos de retiro con nodo destino completado: ').$nodes);
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string, int> estado destino => envíos avanzados (o que avanzarían en dry-run)
     */
    private function syncStatuses(ConnectionInterface $connection, int $chunk, bool $dryRun): array
    {
        /** @var array<string, int> $counts */
        $counts = [
            ShipmentStatus::Planned->value => 0,
            ShipmentStatus::InExecution->value => 0,
            ShipmentStatus::Delivered->value => 0,
        ];

        $lastId = null;
        while (true) {
            $query = $connection->table(self::TABLE)
                ->whereIn('status', [ShipmentStatus::Received->value, ShipmentStatus::Planned->value])
                ->orderBy('id')
                ->limit($chunk);
            if ($lastId !== null) {
                $query->where('id', '>', $lastId);
            }

            $rows = $query->get(['id', 'status']);
            if ($rows->isEmpty()) {
                break;
            }

            /** @var array<string, ShipmentStatus> $current */
            $current = [];
            foreach ($rows as $row) {
                /** @var \stdClass $row */
                $id = is_scalar($row->id ?? null) ? (string) $row->id : '';
                $status = is_string($row->status ?? null) ? ShipmentStatus::tryFrom($row->status) : null;
                if ($id === '' || $status === null) {
                    continue;
                }
                $lastId = $id;
                $current[$id] = $status;
            }

            $targets = $this->derivedTargets($connection, array_keys($current));

            /** @var array<string, list<string>> $idsByTarget */
            $idsByTarget = [];
            foreach ($targets as $id => $target) {
                $from = $current[$id] ?? null;
                if ($from !== null && ($target->progressRank() ?? 0) > ($from->progressRank() ?? 0)) {
                    $idsByTarget[$target->value][] = $id;
                }
            }

            foreach ($idsByTarget as $targetValue => $ids) {
                $target = ShipmentStatus::from($targetValue);
                $counts[$targetValue] += $dryRun ? count($ids) : $this->advance($connection, $ids, $target);
            }

            if ($rows->count() < $chunk) {
                break;
            }
        }

        return $counts;
    }

    /**
     * Estado al que debería estar cada envío según ejecución y planificación (el de mayor avance).
     *
     * @param  list<string>                  $ids
     * @return array<string, ShipmentStatus>
     */
    private function derivedTargets(ConnectionInterface $connection, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        /** @var array<string, ShipmentStatus> $targets */
        $targets = [];
        foreach ($this->executionTargets($connection, $ids) as [$id, $status]) {
            self::raise($targets, $id, $status);
        }
        foreach ($this->plannedIds($connection, $ids) as $id) {
            self::raise($targets, $id, ShipmentStatus::Planned);
        }

        return $targets;
    }

    /**
     * Sube el estado objetivo del envío solo si el nuevo es de MAYOR avance que el ya conocido.
     *
     * @param array<string, ShipmentStatus> $targets
     */
    private static function raise(array &$targets, string $id, ShipmentStatus $status): void
    {
        $known = $targets[$id] ?? null;
        if ($known === null || ($status->progressRank() ?? 0) > ($known->progressRank() ?? 0)) {
            $targets[$id] = $status;
        }
    }

    /**
     * Estado objetivo que se deriva de cada entrega en ejecución (pares envío → estado).
     *
     * @param  list<string>                              $ids
     * @return list<array{0: string, 1: ShipmentStatus}>
     */
    private function executionTargets(ConnectionInterface $connection, array $ids): array
    {
        $pairs = [];
        foreach ($this->safeRows(fn () => $connection->table('execution_deliveries')
            ->whereIn('shipment_id', $ids)
            ->get(['shipment_id', 'status'])) as $row) {
            $status = is_string($row->status ?? null) ? (self::DELIVERY_TARGETS[$row->status] ?? null) : null;
            if ($status !== null && is_scalar($row->shipment_id ?? null)) {
                $pairs[] = [(string) $row->shipment_id, $status];
            }
        }

        return $pairs;
    }

    /**
     * Envíos con parada en una ruta de chofer o en un plan PUBLICADO (ambos casos → PLANNED).
     *
     * @param  list<string> $ids
     * @return list<string>
     */
    private function plannedIds(ConnectionInterface $connection, array $ids): array
    {
        $routeStops = $this->safeRows(fn () => $connection->table('execution_driver_route_stops')
            ->whereIn('shipment_id', $ids)
            ->distinct()
            ->get(['shipment_id']));

        // Solo planes PUBLICADOS: un plan propuesto todavía se re-arma (ver ShipmentStatusProgressionDispatcher).
        $planStops = $this->safeRows(fn () => $connection->table('planning_plan_stops as s')
            ->join('planning_plans as p', 'p.id', '=', 's.plan_id')
            ->whereIn('s.shipment_id', $ids)
            ->where('p.status', 'PUBLISHED')
            ->distinct()
            ->get(['s.shipment_id']));

        $planned = [];
        foreach ([...$routeStops, ...$planStops] as $row) {
            if (is_scalar($row->shipment_id ?? null)) {
                $planned[] = (string) $row->shipment_id;
            }
        }

        return $planned;
    }

    /**
     * Avanza los envíos al estado destino con la guarda del estado de partida (monótono y seguro frente a
     * cambios concurrentes del reactor): solo se actualizan los que siguen en un estado ANTERIOR al destino.
     *
     * @param list<string> $ids
     */
    private function advance(ConnectionInterface $connection, array $ids, ShipmentStatus $target): int
    {
        $before = array_values(array_filter(
            [ShipmentStatus::Received, ShipmentStatus::Planned, ShipmentStatus::InExecution],
            static fn (ShipmentStatus $status): bool => ($status->progressRank() ?? 0) < ($target->progressRank() ?? 0),
        ));

        return $connection->table(self::TABLE)
            ->whereIn('id', $ids)
            ->whereIn('status', array_map(static fn (ShipmentStatus $status): string => $status->value, $before))
            ->update(['status' => $target->value, 'updated_at' => now()]);
    }

    /**
     * Completa el nodo destino de los envíos de retiro a partir del sufijo `#n:{uuid}` de su id externo.
     */
    private function syncDestinationNodes(ConnectionInterface $connection, int $chunk, bool $dryRun): int
    {
        $total = 0;
        $lastId = null;

        while (true) {
            $query = $connection->table(self::TABLE)
                ->whereNull('destination_node_id')
                ->where('external_id', 'like', '%'.SourceReference::NODE_SUFFIX.'%')
                ->orderBy('id')
                ->limit($chunk);
            if ($lastId !== null) {
                $query->where('id', '>', $lastId);
            }

            $rows = $query->get(['id', 'source', 'external_id']);
            if ($rows->isEmpty()) {
                break;
            }

            $nodeByShipment = self::pickupNodesOf($rows, $lastId);
            $total += $this->fillDestinationNodes($connection, $nodeByShipment, $dryRun);

            if ($rows->count() < $chunk) {
                break;
            }
        }

        return $total;
    }

    /**
     * Nodo de retiro de cada envío del tramo (envío → nodo). Avanza `$lastId` al último id válido visto,
     * que es el cursor keyset del tramo siguiente.
     *
     * @param  iterable<mixed>       $rows
     * @return array<string, string>
     */
    private static function pickupNodesOf(iterable $rows, ?string &$lastId): array
    {
        $nodeByShipment = [];
        foreach ($rows as $row) {
            /** @var \stdClass $row */
            $id = is_scalar($row->id ?? null) ? (string) $row->id : '';
            if ($id === '') {
                continue;
            }
            $lastId = $id;

            $node = self::pickupNodeOf($row->source ?? null, $row->external_id ?? null);
            if ($node !== null) {
                $nodeByShipment[$id] = $node;
            }
        }

        return $nodeByShipment;
    }

    /**
     * Escribe el nodo destino de los envíos cuyo nodo existe en la red (o los cuenta en dry-run). La
     * guarda `destination_node_id IS NULL` evita pisar un nodo ya puesto.
     *
     * @param array<string, string> $nodeByShipment
     */
    private function fillDestinationNodes(ConnectionInterface $connection, array $nodeByShipment, bool $dryRun): int
    {
        $existing = $this->existingNodes($connection, $nodeByShipment);

        $total = 0;
        foreach ($nodeByShipment as $shipmentId => $nodeId) {
            if (!isset($existing[$nodeId])) {
                continue;
            }

            $total += $dryRun ? 1 : $connection->table(self::TABLE)
                ->where('id', $shipmentId)
                ->whereNull('destination_node_id')
                ->update(['destination_node_id' => $nodeId, 'updated_at' => now()]);
        }

        return $total;
    }

    /**
     * Solo nodos que existen en la red (un sufijo huérfano no se escribe), en minúsculas.
     *
     * @param  array<string, string> $nodeByShipment
     * @return array<string, true>
     */
    private function existingNodes(ConnectionInterface $connection, array $nodeByShipment): array
    {
        $existing = [];
        foreach ($this->safeRows(fn () => $connection->table('network_nodes')
            ->whereIn('id', array_values(array_unique(array_values($nodeByShipment))))
            ->get(['id'])) as $node) {
            if (is_scalar($node->id ?? null)) {
                $existing[strtolower((string) $node->id)] = true;
            }
        }

        return $existing;
    }

    /** Nodo de retiro del id externo (misma regla del ingreso), o null si no aplica. */
    private static function pickupNodeOf(mixed $source, mixed $externalId): ?string
    {
        $orderSource = is_string($source) ? OrderSource::tryFrom($source) : null;
        if ($orderSource === null || !is_string($externalId)) {
            return null;
        }

        return self::pickupNodeFromReference($orderSource, $externalId);
    }

    /** Sufijo de nodo de retiro del id externo; null si la referencia es inválida (nunca lanza). */
    private static function pickupNodeFromReference(OrderSource $source, string $externalId): ?string
    {
        try {
            return SourceReference::of($source, $externalId)->pickupNodeId();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Ejecuta una lectura cruzada tolerante: si la tabla de otro contexto no está disponible, no hay filas.
     *
     * @param  callable(): iterable<int, mixed> $read
     * @return list<\stdClass>
     */
    private function safeRows(callable $read): array
    {
        try {
            $rows = [];
            foreach ($read() as $row) {
                if ($row instanceof \stdClass) {
                    $rows[] = $row;
                }
            }

            return $rows;
        } catch (Throwable) {
            return [];
        }
    }
}

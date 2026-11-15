<?php

declare(strict_types=1);

namespace Logistics\Shipments\Infrastructure\ReadModel;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Logistics\Shipments\Application\Contract\ShipmentScopeReader;
use Logistics\Shipments\Application\ReadModel\ShipmentOriginView;
use Logistics\Shipments\Application\Scope\ShipmentScopeActor;
use Throwable;

/**
 * Implementación SQL del alcance por rol. La regla NO se repite acá: es el predicado
 * {@see ShipmentSqlFilters::scope()}, el mismo que filtra el listado, aplicado sobre un puñado de ids.
 *
 * Solo lectura por el query builder. Nunca compara contra una columna uuid un valor que no tenga forma de
 * UUID (en PostgreSQL eso falla con 22P02): los ids se validan antes.
 */
final readonly class DbShipmentScopeReader implements ShipmentScopeReader
{
    private const string UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /** Tope de envíos de un mismo pedido que se evalúan (un pedido tiene un puñado de destinos). */
    private const int ORDER_LIMIT = 200;

    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function inScope(ShipmentScopeActor $actor, array $shipmentIds): array
    {
        $ids = self::uuids($shipmentIds);
        if ($ids === [] || $actor->unrestricted) {
            return $ids;
        }

        $query = $this->connection->table('shipments')->whereIn('shipments.id', $ids);
        ShipmentSqlFilters::scope($query, $actor);

        return self::strings($query->pluck('shipments.id')->all());
    }

    public function existing(array $shipmentIds): array
    {
        $ids = self::uuids($shipmentIds);
        if ($ids === []) {
            return [];
        }

        return self::strings($this->connection->table('shipments')->whereIn('id', $ids)->pluck('id')->all());
    }

    public function shipmentIdsOfOrder(string $orderNumber): array
    {
        $orderNumber = trim($orderNumber);
        if ($orderNumber === '') {
            return [];
        }

        $isUuid = preg_match(self::UUID_PATTERN, $orderNumber) === 1;

        $rows = $this->connection->table('shipments')
            ->where(static function (Builder $where) use ($orderNumber, $isUuid): void {
                $where->where('order_reference', $orderNumber)->orWhere('external_id', $orderNumber);
                if ($isUuid) {
                    $where->orWhere('id', strtolower($orderNumber));
                }
            })
            ->orderBy('id')
            ->limit(self::ORDER_LIMIT)
            ->pluck('id');

        return self::strings($rows->all());
    }

    public function origins(array $shipmentIds): array
    {
        $ids = self::uuids($shipmentIds);
        if ($ids === []) {
            return [];
        }

        try {
            $rows = $this->connection->table('shipments as s')
                ->leftJoin('network_nodes as n', 'n.id', '=', 's.origin_node_id')
                ->whereIn('s.id', $ids)
                ->get(['s.id', 's.origin_node_id', 's.origin_department', 's.destination_department', 'n.name as origin_node_name']);
        } catch (Throwable) {
            // La red no está disponible: el alcance del envío queda sin dato legible (nunca rompe la lectura).
            return [];
        }

        $origins = [];
        foreach ($rows as $row) {
            $id = self::text($row->id ?? null);
            if ($id !== null) {
                $origins[$id] = new ShipmentOriginView(
                    originNodeId: self::text($row->origin_node_id ?? null),
                    originNodeName: self::text($row->origin_node_name ?? null),
                    originDepartment: self::text($row->origin_department ?? null),
                    destinationDepartment: self::text($row->destination_department ?? null),
                );
            }
        }

        return $origins;
    }

    /**
     * Solo los valores con forma de UUID, en minúsculas y sin repetidos.
     *
     * @param  list<string> $values
     * @return list<string>
     */
    private static function uuids(array $values): array
    {
        $ids = [];
        foreach ($values as $value) {
            if (preg_match(self::UUID_PATTERN, $value) === 1) {
                $ids[strtolower($value)] = true;
            }
        }

        return array_map(strval(...), array_keys($ids));
    }

    /**
     * @param  array<array-key, mixed> $values
     * @return list<string>
     */
    private static function strings(array $values): array
    {
        $strings = [];
        foreach ($values as $value) {
            $text = self::text($value);
            if ($text !== null) {
                $strings[] = $text;
            }
        }

        return $strings;
    }

    private static function text(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}

<?php

declare(strict_types=1);

namespace Logistics\Shipments\Infrastructure\ReadModel;

use Illuminate\Database\ConnectionInterface;
use Logistics\Shipments\Application\Contract\ShipmentLegReader;
use Logistics\Shipments\Application\ReadModel\ShipmentLegView;

/**
 * Implementación SQL del lado de lectura de los tramos de un envío. Lee la tabla `shipment_legs` por el
 * query builder (solo lectura), sin reconstituir el agregado ni cargar nada más.
 *
 * Nunca compara contra una columna uuid un valor sin forma de UUID (en PostgreSQL eso falla con 22P02):
 * valida la forma del id antes de consultar.
 */
final readonly class DbShipmentLegReader implements ShipmentLegReader
{
    private const string UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function forShipment(string $shipmentId): array
    {
        $shipmentId = trim($shipmentId);
        if (preg_match(self::UUID_PATTERN, $shipmentId) !== 1) {
            return [];
        }

        $rows = $this->connection->table('shipment_legs')
            ->where('shipment_id', strtolower($shipmentId))
            ->orderBy('seq')
            ->get([
                'id', 'seq', 'from_node_id', 'to_node_id', 'from_department', 'to_department',
                'haulage_mode', 'carrier_id', 'status', 'departed_at', 'arrived_at',
            ]);

        $views = [];
        foreach ($rows as $row) {
            $id = self::text($row->id ?? null);
            if ($id === null) {
                continue;
            }

            $views[] = new ShipmentLegView(
                id: $id,
                seq: (int) ($row->seq ?? 0),
                fromNodeId: self::text($row->from_node_id ?? null),
                toNodeId: self::text($row->to_node_id ?? null),
                fromDepartment: self::text($row->from_department ?? null),
                toDepartment: self::text($row->to_department ?? null),
                haulageMode: self::text($row->haulage_mode ?? null) ?? 'own_fleet',
                carrierId: self::text($row->carrier_id ?? null),
                status: self::text($row->status ?? null) ?? 'pending',
                departedAt: self::text($row->departed_at ?? null),
                arrivedAt: self::text($row->arrived_at ?? null),
            );
        }

        return $views;
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

<?php

declare(strict_types=1);

namespace Logistics\Shipments\Infrastructure\ReadModel;

use Illuminate\Database\ConnectionInterface;
use Logistics\Shipments\Application\Contract\ShipmentRoutingFactsReader;
use Logistics\Shipments\Application\ReadModel\ShipmentRoutingFacts;

/**
 * Implementación SQL del lector de datos de ruteo de un envío. Lee la tabla `shipments` por el query builder
 * (solo lectura), sin reconstituir el agregado.
 *
 * Nunca compara contra una columna uuid un valor sin forma de UUID (en PostgreSQL eso falla con 22P02):
 * valida la forma del id antes de consultar.
 */
final readonly class DbShipmentRoutingFactsReader implements ShipmentRoutingFactsReader
{
    private const string UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function factsFor(string $shipmentId): ?ShipmentRoutingFacts
    {
        $shipmentId = trim($shipmentId);
        if (preg_match(self::UUID_PATTERN, $shipmentId) !== 1) {
            return null;
        }

        $row = $this->connection->table('shipments')
            ->where('id', strtolower($shipmentId))
            ->first([
                'id', 'origin_node_id', 'destination_node_id',
                'origin_department', 'destination_department', 'lat', 'lng',
            ]);

        if ($row === null) {
            return null;
        }

        $id = self::text($row->id ?? null);
        if ($id === null) {
            return null;
        }

        return new ShipmentRoutingFacts(
            shipmentId: $id,
            originNodeId: self::text($row->origin_node_id ?? null),
            destinationNodeId: self::text($row->destination_node_id ?? null),
            originDepartment: self::text($row->origin_department ?? null),
            destinationDepartment: self::text($row->destination_department ?? null),
            lat: self::float($row->lat ?? null),
            lng: self::float($row->lng ?? null),
        );
    }

    private static function text(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private static function float(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (float) $value : null;
    }
}

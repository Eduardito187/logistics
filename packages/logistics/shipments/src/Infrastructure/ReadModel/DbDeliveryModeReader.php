<?php

declare(strict_types=1);

namespace Logistics\Shipments\Infrastructure\ReadModel;

use Illuminate\Database\ConnectionInterface;
use Logistics\Shipments\Application\Contract\DeliveryModeReader;
use Logistics\Shipments\Domain\ValueObject\DeliveryMode;
use Throwable;

/**
 * Implementación SQL de la modalidad de entrega: lee `network_nodes.type` en solo lectura (por el query
 * builder, sin acoplar el dominio de `network`) en una consulta por lote. La tabla de nodos es chica
 * (decenas/cientos de filas), así que el costo es constante por página.
 *
 * Tolerante: si la red no está disponible, cada envío con nodo queda como domicilio (el caso seguro: el
 * mostrador de tienda nunca ofrece entregar algo que reparte un chofer).
 */
final readonly class DbDeliveryModeReader implements DeliveryModeReader
{
    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function forDestinationNodes(array $nodeIds): array
    {
        $ids = array_values(array_unique(array_filter($nodeIds, static fn (string $id): bool => $id !== '')));
        if ($ids === []) {
            return [];
        }

        $types = [];
        try {
            foreach (array_chunk($ids, 1000) as $batch) {
                $rows = $this->connection->table('network_nodes')->whereIn('id', $batch)->get(['id', 'type']);
                foreach ($rows as $row) {
                    /** @var \stdClass $row */
                    if (is_scalar($row->id ?? null) && is_scalar($row->type ?? null)) {
                        $types[(string) $row->id] = (string) $row->type;
                    }
                }
            }
        } catch (Throwable) {
            $types = [];
        }

        $result = [];
        foreach ($ids as $id) {
            $result[$id] = DeliveryMode::forDestinationNodeType($types[$id] ?? null);
        }

        return $result;
    }
}

<?php

declare(strict_types=1);

namespace Logistics\Shipments\Infrastructure\ReadModel;

use Illuminate\Database\ConnectionInterface;
use Logistics\Shipments\Application\Contract\ReturnsWarehouseResolver;
use Throwable;

/**
 * Resuelve el almacén central de retornos leyendo la red (`network_nodes`) en SOLO lectura por el query
 * builder, sin acoplar el dominio de `network`: el primer nodo de tipo `central_warehouse` (orden
 * estable por `code`). Degrada a null si la tabla no existe (network no desplegado) o no hay ninguno.
 */
final readonly class NetworkReturnsWarehouseResolver implements ReturnsWarehouseResolver
{
    private const NODES_TABLE = 'network_nodes';

    private const CENTRAL_WAREHOUSE = 'central_warehouse';

    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function resolve(): ?string
    {
        try {
            /** @var \stdClass|null $row */
            $row = $this->connection->table(self::NODES_TABLE)
                ->where('type', self::CENTRAL_WAREHOUSE)
                ->orderBy('code')
                ->first(['id']);
        } catch (Throwable) {
            return null;
        }

        $id = $row->id ?? null;

        return is_scalar($id) && (string) $id !== '' ? (string) $id : null;
    }
}

<?php

declare(strict_types=1);

namespace Logistics\Execution\Infrastructure\ReadModel;

use Illuminate\Database\ConnectionInterface;
use Logistics\Execution\Application\Contract\ShipmentFulfillmentReader;
use Logistics\Shared\Domain\ValueObject\Ownership;

/**
 * Lee `fulfillment_mode` / `owner_party_id` / `seller_sku_ref` directo de la tabla `shipments` con el
 * query builder (sin el Eloquent de shipments → sin acople al otro contexto). Degrada a OWNED cuando no
 * hay fila o la columna aún no existe, para no romper el flujo retail ni los entornos sin la migración.
 */
final readonly class DbShipmentFulfillmentReader implements ShipmentFulfillmentReader
{
    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function forShipmentId(string $shipmentId): Ownership
    {
        if (trim($shipmentId) === '') {
            return Ownership::dismacOwned();
        }

        $row = $this->connection->table('shipments')
            ->where('id', $shipmentId)
            ->first(['fulfillment_mode', 'owner_party_id', 'seller_sku_ref']);

        if ($row === null || !isset($row->fulfillment_mode)) {
            return Ownership::dismacOwned();
        }

        return Ownership::fromPrimitives(
            (string) $row->fulfillment_mode,
            $row->owner_party_id !== null ? (string) $row->owner_party_id : null,
            $row->seller_sku_ref !== null ? (string) $row->seller_sku_ref : null,
        );
    }
}

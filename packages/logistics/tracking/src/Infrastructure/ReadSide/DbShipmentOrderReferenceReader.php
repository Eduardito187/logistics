<?php

declare(strict_types=1);

namespace Logistics\Tracking\Infrastructure\ReadSide;

use Illuminate\Database\ConnectionResolverInterface;
use Logistics\Tracking\Application\Contract\ShipmentOrderReferenceReader;

/**
 * Lee la referencia de la orden de un envío desde `shipments` por el query builder, en solo lectura y sin
 * acoplar el seguimiento al dominio de shipments (mismo patrón que {@see DbCustomerContactReader}).
 *
 * Prefiere la columna `order_reference` (agrega a los hermanos de multishipping bajo la orden original).
 * Si está vacía (filas previas a esa columna), cae al `external_id`: ese valor lleva el id de la orden, o
 * `orderId#n:nodeId` en retiro; la referencia es la parte ANTERIOR a «#n:», idéntica derivación que usa el
 * reactor de ligado {@see \Logistics\Tracking\Infrastructure\Outbox\BindShipmentOnReceived}. Null si el
 * envío no existe o no deja ninguna referencia utilizable.
 */
final readonly class DbShipmentOrderReferenceReader implements ShipmentOrderReferenceReader
{
    public function __construct(
        private ConnectionResolverInterface $connections,
    ) {}

    public function orderReferenceForShipment(string $shipmentId): ?string
    {
        $id = trim($shipmentId);
        if ($id === '') {
            return null;
        }

        $row = $this->connections->connection()
            ->table('shipments')
            ->where('id', $id)
            ->first(['order_reference', 'external_id']);

        if ($row === null) {
            return null;
        }

        $orderReference = is_string($row->order_reference ?? null) ? trim($row->order_reference) : '';
        if ($orderReference !== '') {
            return $orderReference;
        }

        $externalId = is_string($row->external_id ?? null) ? trim($row->external_id) : '';
        if ($externalId === '') {
            return null;
        }

        $reference = trim(explode('#n:', $externalId)[0]);

        return $reference === '' ? null : $reference;
    }
}

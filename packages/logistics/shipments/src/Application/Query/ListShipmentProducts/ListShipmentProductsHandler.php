<?php

declare(strict_types=1);

namespace Logistics\Shipments\Application\Query\ListShipmentProducts;

use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;
use Logistics\Shipments\Application\ReadModel\ShipmentProductView;
use Logistics\Shipments\Domain\Contract\ShipmentRepository;
use Logistics\Shipments\Domain\Exception\ShipmentsException;
use Logistics\Shipments\Domain\ValueObject\ShipmentId;

/**
 * Devuelve los productos de un envío como ReadModels planos. Un envío inexistente devuelve lista vacía
 * (el notificador decide qué hacer); nunca expone el agregado ni los value objects del dominio.
 *
 * @implements QueryHandler<ListShipmentProducts, list<ShipmentProductView>>
 */
final class ListShipmentProductsHandler implements QueryHandler
{
    public function __construct(
        private readonly ShipmentRepository $shipments,
    ) {}

    /**
     * @return list<ShipmentProductView>
     */
    public function handle(Query $query): array
    {
        if (!$query instanceof ListShipmentProducts) {
            throw ShipmentsException::because('ListShipmentProductsHandler solo maneja ListShipmentProducts');
        }

        $shipment = $this->shipments->findById(ShipmentId::fromString($query->shipmentId));
        if ($shipment === null) {
            return [];
        }

        $products = [];
        foreach ($shipment->lines() as $line) {
            $imageUrl = $line->imageUrl();
            $unitPrice = $line->unitPrice();

            $products[] = new ShipmentProductView(
                (string) $line->sku(),
                (string) $line->productName(),
                $line->quantity(),
                $imageUrl !== null ? $imageUrl->value : null,
                $unitPrice?->amount,
                $unitPrice?->currency,
            );
        }

        return $products;
    }
}

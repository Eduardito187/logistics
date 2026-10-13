<?php

declare(strict_types=1);

namespace Logistics\Shipments\Application\Query\ListShipments;

use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;
use Logistics\Shipments\Application\ReadModel\ShipmentView;
use Logistics\Shipments\Domain\Contract\ShipmentRepository;
use Logistics\Shipments\Domain\Exception\ShipmentsException;
use Logistics\Shipments\Domain\ValueObject\OrderSource;
use Logistics\Shipments\Domain\ValueObject\ShipmentStatus;

/**
 * Lista envíos aplicando los filtros opcionales por estado y fuente.
 *
 * Para el volumen del piloto alcanza con filtrar en memoria sobre el repositorio; cuando crezca se
 * reemplaza por una consulta de lectura dedicada (CQRS lo permite sin tocar el dominio).
 *
 * @implements QueryHandler<ListShipments, list<ShipmentView>>
 */
final class ListShipmentsHandler implements QueryHandler
{
    public function __construct(
        private readonly ShipmentRepository $shipments,
    ) {}

    /**
     * @return list<ShipmentView>
     */
    public function handle(Query $query): array
    {
        if (!$query instanceof ListShipments) {
            throw ShipmentsException::because('ListShipmentsHandler solo maneja ListShipments');
        }

        $status = $query->status !== null ? ShipmentStatus::from($query->status) : null;
        $source = $query->source !== null ? OrderSource::from($query->source) : null;

        $views = [];
        foreach ($this->shipments->all() as $shipment) {
            if ($status !== null && $shipment->status() !== $status) {
                continue;
            }
            if ($source !== null && $shipment->source() !== $source) {
                continue;
            }
            $views[] = ShipmentView::fromShipment($shipment);
        }

        return $views;
    }
}

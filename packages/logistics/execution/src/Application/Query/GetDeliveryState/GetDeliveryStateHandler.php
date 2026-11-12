<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Query\GetDeliveryState;

use Logistics\Execution\Application\Contract\DeliveryReferenceReader;
use Logistics\Execution\Application\Contract\ShipmentUnitIdsReader;
use Logistics\Execution\Application\ReadModel\DeliveryView;
use Logistics\Execution\Domain\Contract\DeliveryRepository;
use Logistics\Execution\Domain\Exception\ExecutionException;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * @implements QueryHandler<GetDeliveryState, ?DeliveryView>
 */
final class GetDeliveryStateHandler implements QueryHandler
{
    public function __construct(
        private readonly DeliveryRepository $deliveries,
        private readonly ?DeliveryReferenceReader $references = null,
        private readonly ?ShipmentUnitIdsReader $units = null,
    ) {}

    public function handle(Query $query): ?DeliveryView
    {
        if (!$query instanceof GetDeliveryState) {
            throw ExecutionException::because('GetDeliveryStateHandler solo maneja GetDeliveryState');
        }

        $delivery = $this->deliveries->findByShipmentId($query->shipmentId);

        if ($delivery === null) {
            return null;
        }

        // Referencia legible del envío (pedido, cliente, dirección) para que la app muestre la orden y no
        // un texto genérico; misma fuente que la hoja de ruta del vehículo.
        $reference = $this->references?->forShipmentIds([$delivery->shipmentId()])[$delivery->shipmentId()] ?? null;

        // Bultos del envío (units_total / unit_ids): el POD espera bultos, no cantidades de producto.
        $unitIds = $this->units?->forShipmentIds([$delivery->shipmentId()])[$delivery->shipmentId()] ?? [];

        return DeliveryView::fromDelivery($delivery, $reference, $unitIds);
    }
}

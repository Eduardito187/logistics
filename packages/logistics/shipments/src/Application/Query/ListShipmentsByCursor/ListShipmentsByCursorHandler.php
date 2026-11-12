<?php

declare(strict_types=1);

namespace Logistics\Shipments\Application\Query\ListShipmentsByCursor;

use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;
use Logistics\Shipments\Application\Contract\DeliveryModeReader;
use Logistics\Shipments\Application\Contract\ShipmentListReader;
use Logistics\Shipments\Application\Contract\ShipmentProgressReader;
use Logistics\Shipments\Application\Query\ShipmentListSupport;
use Logistics\Shipments\Application\ReadModel\ShipmentPage;
use Logistics\Shipments\Domain\Exception\ShipmentsException;
use Logistics\Shipments\Domain\ValueObject\OrderSource;

/**
 * Lado de lectura CQRS: lista envíos por cursor (keyset). Delega en ShipmentListReader::listKeyset, que
 * avanza por `id > afterId` (UUID v7) en vez de OFFSET, así una página profunda no escanea-y-descarta.
 *
 * @implements QueryHandler<ListShipmentsByCursor, ShipmentPage>
 */
final class ListShipmentsByCursorHandler implements QueryHandler
{
    public function __construct(
        private readonly ShipmentListReader $reader,
        private readonly ShipmentProgressReader $progress,
        private readonly DeliveryModeReader $deliveryModes,
    ) {}

    public function handle(Query $query): ShipmentPage
    {
        if (!$query instanceof ListShipmentsByCursor) {
            throw ShipmentsException::because('ListShipmentsByCursorHandler solo maneja ListShipmentsByCursor');
        }

        // Se valida/normaliza vía el enum (lanza ante un valor inválido), igual que ListShipments.
        $status = $query->status !== null ? ShipmentListSupport::status($query->status) : null;
        $source = $query->source !== null ? OrderSource::from($query->source)->value : null;
        $statuses = ShipmentListSupport::statuses($query->statuses);
        $deliveryMode = ShipmentListSupport::deliveryMode($query->deliveryMode);

        $page = $this->reader->listKeyset(
            limit: $query->limit,
            afterId: $query->afterId,
            status: $status,
            source: $source,
            destinationNodeId: $query->destinationNodeId,
            deliveryDateFrom: $query->deliveryDateFrom,
            deliveryDateTo: $query->deliveryDateTo,
            statuses: $statuses,
            deliveryMode: $deliveryMode,
        );

        // Avance operativo de la página entera en lote (mismo enriquecimiento que el listado por offset).
        $items = ShipmentListSupport::withDeliveryMode(ShipmentListSupport::withProgress($page->items, $this->progress), $this->deliveryModes);

        return new ShipmentPage($items, $page->nextCursor);
    }
}

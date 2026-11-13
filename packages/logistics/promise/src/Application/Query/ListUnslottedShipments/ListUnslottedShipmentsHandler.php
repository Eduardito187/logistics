<?php

declare(strict_types=1);

namespace Logistics\Promise\Application\Query\ListUnslottedShipments;

use Logistics\Promise\Application\ReadModel\UnslottedShipmentView;
use Logistics\Promise\Domain\Contract\PendingSlotShipmentSource;
use Logistics\Promise\Domain\Exception\PromiseException;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Resuelve la cola "Unslotted": enumera los envíos sloteables (fecha sin ventana) por el mismo puerto que
 * alimenta el barrido y los proyecta a `UnslottedShipmentView`. Solo lectura: no reserva ni escribe.
 *
 * @implements QueryHandler<ListUnslottedShipments, list<UnslottedShipmentView>>
 */
final readonly class ListUnslottedShipmentsHandler implements QueryHandler
{
    public function __construct(
        private PendingSlotShipmentSource $source,
    ) {}

    /**
     * @return list<UnslottedShipmentView>
     */
    public function handle(Query $query): array
    {
        if (!$query instanceof ListUnslottedShipments) {
            throw PromiseException::because('ListUnslottedShipmentsHandler solo maneja ListUnslottedShipments');
        }

        $views = [];
        foreach ($this->source->pendingSlotShipments($query->limit) as $shipment) {
            $views[] = new UnslottedShipmentView($shipment['shipment_id'], $shipment['date']);
        }

        return $views;
    }
}

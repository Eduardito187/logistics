<?php

declare(strict_types=1);

namespace Logistics\Shipments\Application\Query\CheckShipmentScope;

use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;
use Logistics\Shipments\Application\Contract\ShipmentScopeReader;
use Logistics\Shipments\Application\ReadModel\ShipmentScopeDecision;
use Logistics\Shipments\Domain\Exception\ShipmentsException;

/**
 * Resuelve la guarda de escrituras con la regla única del lector de alcance.
 *
 * @implements QueryHandler<CheckShipmentScope, ShipmentScopeDecision>
 */
final class CheckShipmentScopeHandler implements QueryHandler
{
    public function __construct(
        private readonly ShipmentScopeReader $scope,
    ) {}

    public function handle(Query $query): ShipmentScopeDecision
    {
        if (!$query instanceof CheckShipmentScope) {
            throw ShipmentsException::because('CheckShipmentScopeHandler solo maneja CheckShipmentScope');
        }

        $actor = $query->actor;
        if ($actor->unrestricted) {
            return ShipmentScopeDecision::allowed();
        }

        $denied = ShipmentScopeDecision::denied($actor->denialCode, $actor->denialMessage());

        // Pedido completo: alcanza con que uno de sus envíos esté en el alcance.
        $orderNumber = $query->orderNumber !== null ? trim($query->orderNumber) : '';
        if ($orderNumber !== '') {
            $orderShipments = $this->scope->shipmentIdsOfOrder($orderNumber);
            if ($orderShipments !== [] && $this->scope->inScope($actor, $orderShipments) === []) {
                return $denied;
            }
        }

        // Envíos puntuales: cada uno que exista debe estar en el alcance.
        if ($query->shipmentIds !== []) {
            $existing = $this->scope->existing($query->shipmentIds);
            if ($existing !== [] && count($this->scope->inScope($actor, $existing)) < count($existing)) {
                return $denied;
            }
        }

        return ShipmentScopeDecision::allowed();
    }
}

<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Service;

use DateTimeImmutable;
use Logistics\Execution\Application\Contract\ShipmentFulfillmentReader;
use Logistics\Execution\Domain\Contract\DeliveryRepository;
use Logistics\Execution\Domain\Contract\VehicleRouteRepository;
use Logistics\Execution\Domain\Model\Delivery;

/**
 * Entrega del envío para registrar un hecho de campo (carga, intento fallido): devuelve la que ya existe o,
 * si falta, la ABRE con el camión de la ruta donde el envío tiene su parada.
 *
 * Por qué: una ruta armada desde un plan publicado deja la parada sin entrega abierta (la entrega nacía
 * solo al asignar el camión a mano). El chofer con el pedido en su ruta no puede quedar frenado por eso.
 * Sin ruta ni camión no se abre nada: devuelve null y quien llama mantiene su error claro.
 *
 * La entrega recién abierta NO se guarda acá: la persiste quien llama junto con el hecho que registra, y
 * de ahí sale también su `DeliveryOpened`.
 */
final readonly class RouteDeliveryOpener
{
    public function __construct(
        private DeliveryRepository $deliveries,
        private VehicleRouteRepository $routes,
        private ShipmentFulfillmentReader $fulfillment,
    ) {}

    public function forShipment(string $shipmentId, DateTimeImmutable $occurredAt): ?Delivery
    {
        $delivery = $this->deliveries->findByShipmentId($shipmentId);
        if ($delivery !== null) {
            return $delivery;
        }

        $vehicleId = $this->routes->findByShipmentId($shipmentId)?->vehicleId() ?? '';
        if (trim($vehicleId) === '') {
            return null;
        }

        return Delivery::open(
            $this->deliveries->nextIdentity(),
            $shipmentId,
            $vehicleId,
            $this->fulfillment->forShipmentId($shipmentId)->mode,
            $occurredAt,
        );
    }
}

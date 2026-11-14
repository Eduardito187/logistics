<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Command\RecordStoreDrop;

use DateTimeImmutable;
use Logistics\Execution\Application\Contract\ShipmentFulfillmentReader;
use Logistics\Execution\Domain\Contract\DeliveryRepository;
use Logistics\Execution\Domain\Contract\VehicleRouteRepository;
use Logistics\Execution\Domain\Exception\ExecutionException;
use Logistics\Execution\Domain\Model\Delivery;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Registra la ENTREGA EN TIENDA del chofer: marca en la entrega cuándo y en qué tienda quedó el pedido,
 * cierra la parada de su ruta y graba el hecho (que deja el pedido «listo para retirar» y avisa al cliente).
 *
 * La entrega NO se cierra como entregada: sigue abierta hasta que la tienda se la entrega al cliente en el
 * mostrador, con la prueba de entrega de siempre.
 *
 * Idempotente: repetirla no vuelve a grabar el hecho ni a avisar. Si la entrega nunca se abrió, se abre con
 * el camión de la ruta (igual que la prueba de entrega); sin entrega ni ruta no hay contexto y se rechaza.
 *
 * @implements CommandHandler<RecordStoreDrop>
 */
final class RecordStoreDropHandler implements CommandHandler
{
    public function __construct(
        private readonly DeliveryRepository $deliveries,
        private readonly VehicleRouteRepository $routes,
        private readonly ShipmentFulfillmentReader $fulfillment,
        private readonly RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        if (!$command instanceof RecordStoreDrop) {
            throw ExecutionException::because('RecordStoreDropHandler solo maneja RecordStoreDrop');
        }

        $occurredAt = new DateTimeImmutable($command->occurredAt);
        $route = $this->routes->findByShipmentId($command->shipmentId);

        $delivery = $this->deliveries->findByShipmentId($command->shipmentId);
        if ($delivery === null) {
            $vehicleId = $route?->vehicleId() ?? '';
            if (trim($vehicleId) === '') {
                throw ExecutionException::because("no hay entrega abierta para el envío {$command->shipmentId}");
            }

            $delivery = Delivery::open(
                $this->deliveries->nextIdentity(),
                $command->shipmentId,
                $vehicleId,
                $this->fulfillment->forShipmentId($command->shipmentId)->mode,
                $occurredAt,
            );
        }

        $delivery->dropAtStore($command->nodeId, $occurredAt, $command->photoPath);

        $this->deliveries->save($delivery);
        $this->recordEvents->record($delivery->releaseEvents(), 'execution.delivery');

        // La parada del chofer queda resuelta: el camión ya no lleva este pedido.
        if ($route !== null) {
            $route->completeStop($command->shipmentId, $occurredAt);
            $this->routes->save($route);
            $this->recordEvents->record($route->releaseEvents(), 'execution.route');
        }
    }
}

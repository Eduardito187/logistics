<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Command\RecordProofOfDelivery;

use DateTimeImmutable;
use Logistics\Execution\Application\Contract\ShipmentFulfillmentReader;
use Logistics\Execution\Domain\Contract\DeliveryRepository;
use Logistics\Execution\Domain\Contract\VehicleRouteRepository;
use Logistics\Execution\Domain\Exception\ExecutionException;
use Logistics\Execution\Domain\Model\Delivery;
use Logistics\Execution\Domain\ValueObject\DeviceContext;
use Logistics\Execution\Domain\ValueObject\GeoPoint;
use Logistics\Execution\Domain\ValueObject\ProofOfDelivery as ProofOfDeliveryVO;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Carga la entrega del envío y registra la prueba de entrega. Como la entrega es el desenlace exitoso de
 * una PARADA de la ruta del chofer, el mismo comando AVANZA la ruta: cierra esa parada y deja la
 * siguiente como actual (si el camión tiene ruta armada). Así "deja una y procede con la siguiente".
 *
 * Idempotente: el agregado de la entrega descarta el escaneo si ya existe su idempotencyKey, y la ruta
 * ignora el cierre de una parada ya cerrada. El save de ambos agregados y el append al outbox corren en
 * la misma transacción (decorador transaccional del CommandBus).
 *
 * @implements CommandHandler<RecordProofOfDelivery>
 */
final class RecordProofOfDeliveryHandler implements CommandHandler
{
    public function __construct(
        private readonly DeliveryRepository $deliveries,
        private readonly VehicleRouteRepository $routes,
        private readonly RecordDomainEvents $recordEvents,
        private readonly ShipmentFulfillmentReader $fulfillment,
    ) {}

    public function handle(Command $command): void
    {
        if (!$command instanceof RecordProofOfDelivery) {
            throw ExecutionException::because('RecordProofOfDeliveryHandler solo maneja RecordProofOfDelivery');
        }

        $occurredAt = new DateTimeImmutable($command->occurredAt);

        // Auto-abrir si NO hay entrega: un POD puede llegar para un envío cuya entrega nunca se abrió (el
        // camión se asignó por un camino que no disparó OpenDelivery, o antes de ese fix). En vez de
        // bloquear al chofer, abrimos la entrega con el camión de su ruta (abre en Loaded) y seguimos — el
        // guard del POD ya acepta Loaded→Delivered. Sin ruta/camión solo se abre si es una entrega de
        // MOSTRADOR (rol tienda, retiro en tienda); en cualquier otro caso se mantiene el error claro.
        $delivery = $this->deliveries->findByShipmentId($command->shipmentId);
        if ($delivery === null) {
            $route = $this->routes->findByShipmentId($command->shipmentId);
            $vehicleId = $route?->vehicleId() ?? '';
            if (trim($vehicleId) === '' && !$command->counterHandover) {
                throw ExecutionException::because("no hay entrega abierta para el envío {$command->shipmentId}");
            }

            $mode = $this->fulfillment->forShipmentId($command->shipmentId)->mode;
            // Retiro en tienda: el cliente retira en el mostrador, sin camión ni ruta. La tienda abre la
            // entrega de mostrador y la misma prueba de entrega la cierra como entregada.
            $delivery = trim($vehicleId) === ''
                ? Delivery::openAtCounter($this->deliveries->nextIdentity(), $command->shipmentId, $mode, $occurredAt)
                : Delivery::open($this->deliveries->nextIdentity(), $command->shipmentId, $vehicleId, $mode, $occurredAt);
        }

        $unitIds = $command->unitIds !== [] ? $command->unitIds : [$command->unitId];

        $delivery->recordProofOfDeliveryForUnits(
            $unitIds,
            ProofOfDeliveryVO::of(
                $command->confirmation,
                $command->photoUrl,
                $occurredAt,
                $command->photoPath,
                $command->photoPaths,
                $command->evidenceIds,
            ),
            GeoPoint::fromNullable(
                $command->lat,
                $command->lng,
                $command->accuracyM,
                $command->locationCapturedAt !== null ? new DateTimeImmutable($command->locationCapturedAt) : null,
            ),
            DeviceContext::of($command->deviceId, $command->userId),
            $occurredAt,
            $command->idempotencyKey,
        );

        $this->deliveries->save($delivery);

        $this->recordEvents->record($delivery->releaseEvents(), 'execution.delivery');

        // Avanza la ruta del chofer: cierra esta parada y expone la siguiente como actual. Solo si el
        // camión tiene una ruta armada que incluye este envío; si no, la entrega sigue siendo válida.
        $route = $this->routes->findByShipmentId($command->shipmentId);
        if ($route !== null) {
            $route->completeStop($command->shipmentId, $occurredAt);
            $this->routes->save($route);
            $this->recordEvents->record($route->releaseEvents(), 'execution.route');
        }
    }
}

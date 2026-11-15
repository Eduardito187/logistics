<?php

declare(strict_types=1);

namespace Logistics\Planning\Application\Command\AssignShipmentToVehicle;

use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Planning\Application\Contract\ShipmentPlanningFactsReader;
use Logistics\Planning\Application\Policy\ClientStopLocation;
use Logistics\Planning\Application\Policy\RouteDepartmentPolicy;
use Logistics\Planning\Application\Service\PlanGeometryRefresher;
use Logistics\Planning\Domain\Contract\PlanRepository;
use Logistics\Planning\Domain\Exception\PlanningException;
use Logistics\Planning\Domain\ValueObject\PlanId;
use Logistics\Planning\Domain\ValueObject\PlannedStopId;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Carga el plan, le agrega el envío, lo persiste y publica sus eventos al outbox.
 *
 * El save del repositorio y el append al outbox corren dentro de la misma transacción, que abre el
 * decorador transaccional del CommandBus (Infrastructure); por eso acá no hay nada del framework.
 *
 * Lo que el comando no trae del envío (coordenadas, referencia del pedido, cliente, dirección) se completa
 * con los datos del envío, leídos por contrato en una sola lectura. Un envío que EXISTE y no tiene
 * ubicación (ni en el comando ni propia) no se puede sumar: corta con un motivo legible (422). Un id que
 * no corresponde a ningún envío conserva el comportamiento previo (se asigna sin datos legibles).
 *
 * @implements CommandHandler<AssignShipmentToVehicle>
 */
final readonly class AssignShipmentToVehicleHandler implements CommandHandler
{
    public function __construct(
        private PlanRepository $plans,
        private RecordDomainEvents $recordEvents,
        private RouteDepartmentPolicy $geography,
        private ShipmentPlanningFactsReader $shipments,
        private ?PlanGeometryRefresher $geometry = null,
    ) {}

    public function handle(Command $command): void
    {
        if (!$command instanceof AssignShipmentToVehicle) {
            throw PlanningException::because('AssignShipmentToVehicleHandler solo maneja AssignShipmentToVehicle');
        }

        $plan = $this->plans->findById(PlanId::fromString($command->planId))
            ?? throw PlanningException::because("no existe el plan {$command->planId}");

        // Datos del envío que el comando no trajo (una sola lectura; nada si el comando vino completo).
        $complete = $command->orderReference !== null && $command->customerName !== null && $command->address !== null
            && $command->latitude !== null && $command->longitude !== null;
        $shipmentKey = strtolower(trim($command->shipmentId));
        $fact = $complete ? null : ($this->shipments->factsFor([$shipmentKey])[$shipmentKey] ?? null);

        $latitude = $command->latitude;
        $longitude = $command->longitude;
        if ($latitude === null || $longitude === null) {
            $latitude = $fact?->latitude;
            $longitude = $fact?->longitude;
        }
        if ($fact !== null && ($latitude === null || $longitude === null)) {
            throw PlanningException::because(
                ucfirst($fact->label()).' no tiene ubicación en el mapa: corrija su dirección antes de sumarlo al viaje.',
            );
        }

        $customerName = $command->customerName ?? $fact?->customerName;
        $isClientStop = $command->customerName !== null
            || ($fact !== null && $fact->customerName !== null && !$fact->isTransfer());

        // Invariante geográfica: con las paradas de cliente que ya tiene el plan más la nueva (si
        // entrega a cliente y tiene coordenadas), todas deben caer en el mismo departamento. Un envío
        // sin cliente (traspaso CD→CD) o sin coordenadas no entra a la regla (exento/tolerante).
        $clientStops = $command->currentClientStops;
        if ($isClientStop && $latitude !== null && $longitude !== null) {
            $clientStops[] = ClientStopLocation::of($command->shipmentId, $latitude, $longitude);
        }
        $this->geography->guard($clientStops);

        $plan->assignShipment(
            PlannedStopId::generate(),
            $command->shipmentId,
            $command->orderReference ?? $fact?->orderReference,
            $customerName,
            $command->address ?? $fact?->address,
        );

        // La parada nueva cambia el recorrido: se recalcula la geometría (degrada sin romper).
        $this->geometry?->refresh($plan);

        $this->plans->save($plan);

        $this->recordEvents->record($plan->releaseEvents(), 'planning.plan');
    }
}

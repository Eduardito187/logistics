<?php

declare(strict_types=1);

namespace Logistics\Planning\Application\Command\AssignShipmentToVehicle;

use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Planning\Application\Policy\ClientStopLocation;
use Logistics\Planning\Application\Policy\RouteDepartmentPolicy;
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
 * @implements CommandHandler<AssignShipmentToVehicle>
 */
final readonly class AssignShipmentToVehicleHandler implements CommandHandler
{
    public function __construct(
        private PlanRepository $plans,
        private RecordDomainEvents $recordEvents,
        private RouteDepartmentPolicy $geography,
    ) {}

    public function handle(Command $command): void
    {
        if (!$command instanceof AssignShipmentToVehicle) {
            throw PlanningException::because('AssignShipmentToVehicleHandler solo maneja AssignShipmentToVehicle');
        }

        $plan = $this->plans->findById(PlanId::fromString($command->planId))
            ?? throw PlanningException::because("no existe el plan {$command->planId}");

        // Invariante geográfica: con las paradas de cliente que ya tiene el plan más la nueva (si
        // entrega a cliente y trae coordenadas), todas deben caer en el mismo departamento. Un envío
        // sin cliente (traspaso CD→CD) o sin coordenadas no entra a la regla (exento/tolerante).
        $clientStops = $command->currentClientStops;
        if ($command->customerName !== null && $command->latitude !== null && $command->longitude !== null) {
            $clientStops[] = ClientStopLocation::of(
                $command->shipmentId,
                $command->latitude,
                $command->longitude,
            );
        }
        $this->geography->guard($clientStops);

        $plan->assignShipment(
            PlannedStopId::generate(),
            $command->shipmentId,
            $command->orderReference,
            $command->customerName,
            $command->address,
        );

        $this->plans->save($plan);

        $this->recordEvents->record($plan->releaseEvents(), 'planning.plan');
    }
}

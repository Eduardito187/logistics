<?php

declare(strict_types=1);

namespace Logistics\Planning\Application\Command\ReassignShipmentToVehicle;

use Logistics\Outbox\Application\RecordDomainEvents;
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
 * Mueve un envío de un plan a otro del mismo día: carga ambos planes, valida las invariantes del plan de
 * destino, quita la parada del origen y la agrega al destino, persiste los dos y publica sus eventos al
 * outbox.
 *
 * Todo ocurre dentro de la MISMA transacción que abre el decorador transaccional del CommandBus
 * (Infrastructure); por eso acá no hay nada del framework. Si el origen suelta la parada pero el destino
 * la rechaza (o al revés), la transacción se revierte entera: nunca queda un envío en dos camiones ni en
 * ninguno.
 *
 * @implements CommandHandler<ReassignShipmentToVehicle>
 */
final readonly class ReassignShipmentToVehicleHandler implements CommandHandler
{
    public function __construct(
        private PlanRepository $plans,
        private RecordDomainEvents $recordEvents,
        private RouteDepartmentPolicy $geography,
        private ?PlanGeometryRefresher $geometry = null,
    ) {}

    public function handle(Command $command): void
    {
        if (!$command instanceof ReassignShipmentToVehicle) {
            throw PlanningException::because('ReassignShipmentToVehicleHandler solo maneja ReassignShipmentToVehicle');
        }

        if ($command->fromPlanId === $command->toPlanId) {
            throw PlanningException::because('el plan de origen y el de destino son el mismo');
        }

        $from = $this->plans->findById(PlanId::fromString($command->fromPlanId))
            ?? throw PlanningException::because("no existe el plan de origen {$command->fromPlanId}");
        $to = $this->plans->findById(PlanId::fromString($command->toPlanId))
            ?? throw PlanningException::because("no existe el plan de destino {$command->toPlanId}");

        // Solo se mueve entre planes del MISMO día: mezclar días cambiaría la promesa de entrega.
        if (!$from->date()->equals($to->date())) {
            throw PlanningException::because('no se puede mover un envío entre planes de días distintos');
        }

        // Invariante geográfica del plan de DESTINO: con las paradas de cliente que ya tiene más la que
        // llega (si entrega a cliente y trae coordenadas), todas deben caer en el mismo departamento. Un
        // envío sin cliente (traspaso CD→CD) o sin coordenadas no entra a la regla (exento/tolerante).
        $clientStops = $command->destinationClientStops;
        if ($command->customerName !== null && $command->latitude !== null && $command->longitude !== null) {
            $clientStops[] = ClientStopLocation::of(
                $command->shipmentId,
                $command->latitude,
                $command->longitude,
            );
        }
        $this->geography->guard($clientStops);

        // Invariante de capacidad del vehículo de DESTINO: no puede exceder su cupo de paradas. Tolerante:
        // sin cupo conocido (null) no bloquea. Se valida antes de mutar para no dejar el origen a medias.
        if ($command->toVehicleCapacity !== null && count($to->stops()) + 1 > $command->toVehicleCapacity) {
            throw PlanningException::because(
                "el vehículo de destino no tiene capacidad para otra parada (cupo {$command->toVehicleCapacity})",
            );
        }

        $from->removeStop($command->shipmentId);

        $to->assignShipment(
            PlannedStopId::generate(),
            $command->shipmentId,
            $command->orderReference,
            $command->customerName,
            $command->address,
        );

        // Ambos recorridos cambiaron: se recalcula la geometría de los dos (degrada sin romper).
        $this->geometry?->refresh($from);
        $this->geometry?->refresh($to);

        $this->plans->save($from);
        $this->plans->save($to);

        // Ambos agregados publican sus eventos al outbox en la misma transacción (el origen emite
        // StopRemovedFromPlan; el destino, por ahora, no graba evento al asignar).
        $this->recordEvents->record($from->releaseEvents(), 'planning.plan');
        $this->recordEvents->record($to->releaseEvents(), 'planning.plan');
    }
}

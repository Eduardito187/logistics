<?php

declare(strict_types=1);

namespace Logistics\Tracking\Application\Command\CorrectShipmentVisibleState;

use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Command\CommandHandler;
use Logistics\Shared\Domain\Clock\Clock;
use Logistics\Tracking\Application\Command\AdvanceTracking\AdvanceTracking;
use Logistics\Tracking\Domain\Contract\TrackingRepository;
use Logistics\Tracking\Domain\Exception\TrackingException;
use Logistics\Tracking\Domain\Model\ShipmentTracking;
use Logistics\Tracking\Domain\ValueObject\TrackingId;
use Logistics\Tracking\Domain\ValueObject\VisibleStatus;

/**
 * Aplica una corrección manual del estado visible reutilizando el avance tolerante ({@see AdvanceTracking})
 * y deja la auditoría en la bitácora ({@see ShipmentTracking::recordCorrection()}).
 *
 * Valida que el estado destino sea uno de los CORREGIBLES a mano; rechaza los que gobierna un flujo real
 * (entregado/anulado/devuelto/pendiente). Solo audita donde la corrección efectivamente movió el estado.
 *
 * @implements CommandHandler<CorrectShipmentVisibleState>
 */
final class CorrectShipmentVisibleStateHandler implements CommandHandler
{
    /** Estados que un operador puede fijar a mano; el resto los gobierna su flujo real (no se corrigen acá). */
    private const array CORRECTABLE = [
        VisibleStatus::Processing,
        VisibleStatus::InProgress,
        VisibleStatus::Loaded,
        VisibleStatus::OnTheWay,
        VisibleStatus::HandedToCarrier,
    ];

    public function __construct(
        private readonly TrackingRepository $trackings,
        private readonly CommandBus $commandBus,
        private readonly RecordDomainEvents $recordEvents,
        private readonly Clock $clock,
    ) {}

    public function handle(Command $command): void
    {
        if (!$command instanceof CorrectShipmentVisibleState) {
            throw TrackingException::because('CorrectShipmentVisibleStateHandler solo maneja CorrectShipmentVisibleState');
        }

        $shipmentId = $this->normalize($command->shipmentId);
        $orderReference = $this->normalize($command->orderReference);
        if ($shipmentId === null && $orderReference === null) {
            throw TrackingException::because('la corrección necesita un shipmentId o un orderReference');
        }

        $reason = trim($command->reason);
        if ($reason === '') {
            throw TrackingException::because('la corrección necesita un motivo');
        }

        $actorId = trim($command->actorId);
        $actorName = trim($command->actorName);
        if ($actorId === '' || $actorName === '') {
            throw TrackingException::because('la corrección necesita el actor que la realiza');
        }

        $target = VisibleStatus::tryFromLoose($command->targetStatus);
        if ($target === null || !in_array($target, self::CORRECTABLE, true)) {
            throw TrackingException::because("estado no corregible a mano: {$command->targetStatus}");
        }

        // Estado previo de cada seguimiento afectado, para la bitácora (antes de mover nada).
        $before = [];
        foreach ($this->resolveTargets($shipmentId, $orderReference) as $tracking) {
            $before[$tracking->id()->toString()] = $tracking->status();
        }
        if ($before === []) {
            return;
        }

        // Aplica el avance REUTILIZANDO el comando tolerante: salta lo que no aplica y respeta la máquina.
        $this->commandBus->dispatch(new AdvanceTracking(
            status: $target,
            shipmentId: $shipmentId,
            orderReference: $orderReference,
        ));

        $at = $this->clock->now();

        // Deja el rastro de auditoría SOLO donde la corrección efectivamente movió el estado al destino.
        foreach ($before as $trackingId => $from) {
            $tracking = $this->trackings->findById(TrackingId::fromString((string) $trackingId));
            if ($tracking === null || $from === $target || $tracking->status() !== $target) {
                continue;
            }

            $tracking->recordCorrection($from, $target, $reason, $actorId, $actorName, $at, $command->notifyCustomer);
            $this->trackings->save($tracking);
            $this->recordEvents->record($tracking->releaseEvents(), 'tracking.shipment_tracking');
        }
    }

    private function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * @return list<ShipmentTracking>
     */
    private function resolveTargets(?string $shipmentId, ?string $orderReference): array
    {
        if ($shipmentId !== null) {
            $one = $this->trackings->findByShipmentId($shipmentId);

            return $one === null ? [] : [$one];
        }

        if ($orderReference !== null) {
            return $this->trackings->findAllByOrderReference($orderReference);
        }

        return [];
    }
}

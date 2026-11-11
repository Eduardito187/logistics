<?php

declare(strict_types=1);

namespace Logistics\Tracking\Application\Command\AdvanceTracking;

use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;
use Logistics\Tracking\Domain\Contract\TrackingRepository;
use Logistics\Tracking\Domain\Exception\TrackingException;
use Logistics\Tracking\Domain\Model\ShipmentTracking;

/**
 * Aplica un avance de estado tolerante sobre uno (por envío) o varios (por pedido) seguimientos. Saltea
 * los que no pueden aplicar la transición desde su estado actual, sin romper la proyección.
 *
 * @implements CommandHandler<AdvanceTracking>
 */
final class AdvanceTrackingHandler implements CommandHandler
{
    public function __construct(
        private readonly TrackingRepository $trackings,
        private readonly RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        if (!$command instanceof AdvanceTracking) {
            throw TrackingException::because('AdvanceTrackingHandler solo maneja AdvanceTracking');
        }

        foreach ($this->resolveTargets($command) as $tracking) {
            if ($tracking->status() === $command->status || !$tracking->canApply($command->status)) {
                continue;
            }

            $tracking->applyStatus($command->status);
            $this->trackings->save($tracking);
            $this->recordEvents->record($tracking->releaseEvents(), 'tracking.shipment_tracking');
        }
    }

    /**
     * @return list<ShipmentTracking>
     */
    private function resolveTargets(AdvanceTracking $command): array
    {
        if ($command->shipmentId !== null && $command->shipmentId !== '') {
            $one = $this->trackings->findByShipmentId($command->shipmentId);

            return $one === null ? [] : [$one];
        }

        if ($command->orderReference !== null && $command->orderReference !== '') {
            return $this->trackings->findAllByOrderReference($command->orderReference);
        }

        return [];
    }
}

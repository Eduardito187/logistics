<?php

declare(strict_types=1);

namespace Logistics\Planning\Application\Command\ScheduleReplan;

use Logistics\Planning\Domain\Contract\ReplanBacklogRepository;
use Logistics\Planning\Domain\Exception\PlanningException;
use Logistics\Planning\Domain\ValueObject\PlanDate;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Encola los envíos del comando en el backlog de replanificación para la fecha dada. Cada envío es
 * idempotente por envío+fecha, así que reencolar no duplica. No emite eventos: es una cola de trabajo
 * que consume la planificación del día siguiente.
 *
 * @implements CommandHandler<ScheduleReplan>
 */
final readonly class ScheduleReplanHandler implements CommandHandler
{
    public function __construct(
        private ReplanBacklogRepository $backlog,
    ) {}

    public function handle(Command $command): void
    {
        if (!$command instanceof ScheduleReplan) {
            throw PlanningException::because('ScheduleReplanHandler solo maneja ScheduleReplan');
        }

        $date = PlanDate::fromString($command->planDate);

        foreach ($command->shipmentIds as $shipmentId) {
            $trimmed = trim($shipmentId);
            if ($trimmed === '') {
                continue;
            }

            $this->backlog->enqueue($trimmed, $date, $command->reason, $command->sourceRouteId);
        }
    }
}

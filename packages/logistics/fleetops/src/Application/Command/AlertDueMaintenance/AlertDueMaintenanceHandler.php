<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\Command\AlertDueMaintenance;

use DateTimeImmutable;
use Logistics\FleetOps\Application\ReadModel\DueMaintenanceView;
use Logistics\FleetOps\Application\Service\DueMaintenanceScanner;
use Logistics\FleetOps\Domain\Event\FleetMaintenanceDueAlerted;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;
use Logistics\Shared\Domain\Clock\Clock;

/**
 * Barrido programado de mantenimiento preventivo por vencer/vencido. Si encuentra al menos uno, emite un
 * único evento de resumen al outbox (FleetMaintenanceDueAlerted); el relay avisa al supervisor. Sin I/O
 * externo síncrono (principio 4). Deja el resumen de la última corrida para el comando de consola.
 *
 * @implements CommandHandler<AlertDueMaintenance>
 */
final class AlertDueMaintenanceHandler implements CommandHandler
{
    private int $lastOverdue = 0;

    private int $lastDueSoon = 0;

    private bool $lastEmitted = false;

    public function __construct(
        private readonly DueMaintenanceScanner $scanner,
        private readonly RecordDomainEvents $recordEvents,
        private readonly Clock $clock,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof AlertDueMaintenance);

        $asOf = $command->referenceDate !== null
            ? new DateTimeImmutable($command->referenceDate)
            : $this->clock->now();

        $views = $this->scanner->scan(
            $asOf,
            (int) config('logistics-fleetops.maintenance.due_soon_km', 500),
            (int) config('logistics-fleetops.maintenance.due_soon_days', 7),
        );

        $this->lastOverdue = count(array_filter($views, static fn (DueMaintenanceView $v): bool => $v->overdue));
        $this->lastDueSoon = count($views) - $this->lastOverdue;
        $this->lastEmitted = false;

        if ($views === []) {
            return;
        }

        $items = array_map(static fn (DueMaintenanceView $v): array => $v->toArray(), $views);

        $event = new FleetMaintenanceDueAlerted(
            'fleetops-maintenance-due-'.$asOf->format('Y-m-d'),
            $asOf->format('Y-m-d'),
            $this->lastOverdue,
            $this->lastDueSoon,
            array_values($items),
        );

        $this->recordEvents->record([$event], 'fleetops.maintenance_due');
        $this->lastEmitted = true;
    }

    public function lastOverdue(): int
    {
        return $this->lastOverdue;
    }

    public function lastDueSoon(): int
    {
        return $this->lastDueSoon;
    }

    public function lastTotal(): int
    {
        return $this->lastOverdue + $this->lastDueSoon;
    }

    public function lastEmitted(): bool
    {
        return $this->lastEmitted;
    }
}

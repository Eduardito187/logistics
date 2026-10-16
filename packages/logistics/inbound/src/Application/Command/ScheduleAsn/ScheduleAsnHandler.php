<?php

declare(strict_types=1);

namespace Logistics\Inbound\Application\Command\ScheduleAsn;

use Logistics\Inbound\Domain\Contract\AsnRepository;
use Logistics\Inbound\Domain\Contract\DockQuotaReserver;
use Logistics\Inbound\Domain\Exception\InboundException;
use Logistics\Inbound\Domain\ValueObject\AppointmentWindow;
use Logistics\Inbound\Domain\ValueObject\AsnId;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Agenda la cita del ASN y reserva el cupo de andén de recepción.
 *
 * El cupo se consume por el puerto DockQuotaReserver (adaptador → capacity) dentro de la misma
 * transacción que abre el CommandBus: si el cupo está lleno, lanza y se revierte todo el agendamiento.
 *
 * @implements CommandHandler<ScheduleAsn>
 */
final readonly class ScheduleAsnHandler implements CommandHandler
{
    public function __construct(
        private AsnRepository $asns,
        private DockQuotaReserver $dockQuota,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof ScheduleAsn);

        $asn = $this->asns->findById(AsnId::fromString($command->asnId))
            ?? throw InboundException::because("No existe el ASN {$command->asnId}.");

        $window = AppointmentWindow::of($command->appointmentDate, $command->startTime, $command->endTime);

        $asn->schedule($window);

        // Reserva el cupo de andén (almacén + día + franja). Dentro de la transacción del bus.
        $this->dockQuota->reserve($asn->warehouseId(), $window);

        $this->asns->save($asn);

        $this->recordEvents->record($asn->releaseEvents(), 'inbound.asn');
    }
}

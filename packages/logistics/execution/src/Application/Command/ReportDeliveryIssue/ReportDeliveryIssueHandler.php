<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Command\ReportDeliveryIssue;

use DateTimeImmutable;
use Logistics\Execution\Domain\Contract\DeliveryIssueRepository;
use Logistics\Execution\Domain\Exception\ExecutionException;
use Logistics\Execution\Domain\Model\DeliveryIssue;
use Logistics\Execution\Domain\ValueObject\DeliveryIssueType;
use Logistics\Execution\Domain\ValueObject\DeviceContext;
use Logistics\Execution\Domain\ValueObject\GeoPoint;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Maneja el reporte de un inconveniente de entrega del chofer.
 *
 * Deja constancia inmutable del hecho (append-only) y graba el evento de dominio que la torre de control y
 * las notificaciones consumen por el outbox (principio 4: nada de I/O síncrono acá). NO cierra ni muta la
 * entrega. El save del hecho y el append al outbox corren en la misma transacción que abre el decorador
 * transaccional del CommandBus.
 *
 * Idempotente por idempotencyKey: el reenvío del mismo reporte de la app offline no duplica el hecho.
 *
 * @implements CommandHandler<ReportDeliveryIssue>
 */
final class ReportDeliveryIssueHandler implements CommandHandler
{
    public function __construct(
        private readonly DeliveryIssueRepository $issues,
        private readonly RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        if (!$command instanceof ReportDeliveryIssue) {
            throw ExecutionException::because('ReportDeliveryIssueHandler solo maneja ReportDeliveryIssue');
        }

        // Idempotencia: si ya se registró este reporte, no se repite el hecho ni el evento.
        if ($this->issues->existsByIdempotencyKey($command->idempotencyKey)) {
            return;
        }

        $type = DeliveryIssueType::from($command->type);

        $occurredAt = $command->occurredAt !== null && trim($command->occurredAt) !== ''
            ? new DateTimeImmutable($command->occurredAt)
            : new DateTimeImmutable;

        $location = $command->lat !== null && $command->lng !== null
            ? GeoPoint::of($command->lat, $command->lng)
            : null;

        $issue = DeliveryIssue::report(
            $this->issues->nextIdentity(),
            $command->shipmentId,
            $type,
            $command->note,
            $location,
            $command->photoPath,
            DeviceContext::of(
                $command->deviceId !== '' ? $command->deviceId : 'unknown-device',
                $command->userId !== '' ? $command->userId : 'unknown-user',
            ),
            $occurredAt,
            $command->idempotencyKey,
        );

        $this->issues->save($issue);

        $this->recordEvents->record($issue->releaseEvents(), 'execution.delivery_issue');
    }
}

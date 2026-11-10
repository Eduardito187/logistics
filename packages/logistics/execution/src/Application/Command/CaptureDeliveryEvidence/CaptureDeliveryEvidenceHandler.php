<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Command\CaptureDeliveryEvidence;

use DateTimeImmutable;
use Logistics\Execution\Domain\Contract\DeliveryEvidenceRepository;
use Logistics\Execution\Domain\Exception\ExecutionException;
use Logistics\Execution\Domain\Model\DeliveryEvidence;
use Logistics\Execution\Domain\ValueObject\CustomerIdentitySnapshot;
use Logistics\Execution\Domain\ValueObject\DeviceContext;
use Logistics\Execution\Domain\ValueObject\EvidenceKind;
use Logistics\Execution\Domain\ValueObject\GeoPoint;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Maneja la captura de una evidencia de entrega ligada al cliente.
 *
 * Deja constancia inmutable del hecho (con hash de integridad y auditoría) y graba el evento
 * DeliveryEvidenceCaptured en el outbox, que dispara la validación anti-fraude asíncrona (nada de I/O
 * síncrono acá). El save y el append al outbox corren en la misma transacción del CommandBus.
 *
 * Idempotente por idempotencyKey: el reenvío de la app offline no duplica la evidencia.
 *
 * @implements CommandHandler<CaptureDeliveryEvidence>
 */
final class CaptureDeliveryEvidenceHandler implements CommandHandler
{
    public function __construct(
        private readonly DeliveryEvidenceRepository $evidence,
        private readonly RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        if (!$command instanceof CaptureDeliveryEvidence) {
            throw ExecutionException::because('CaptureDeliveryEvidenceHandler solo maneja CaptureDeliveryEvidence');
        }

        if ($this->evidence->existsByIdempotencyKey($command->idempotencyKey)) {
            return;
        }

        $capturedAt = $command->capturedAt !== null && trim($command->capturedAt) !== ''
            ? new DateTimeImmutable($command->capturedAt)
            : new DateTimeImmutable;

        $location = $command->lat !== null && $command->lng !== null
            ? GeoPoint::of($command->lat, $command->lng)
            : null;

        $evidence = DeliveryEvidence::capture(
            $this->evidence->nextIdentity(),
            $command->shipmentId,
            EvidenceKind::fromCode($command->kind),
            CustomerIdentitySnapshot::of(
                $command->customerId,
                $command->documentId,
                $command->customerName,
                $command->customerPhone,
            ),
            $command->storageDisk,
            $command->storagePath,
            $command->sha256,
            DeviceContext::of(
                $command->deviceId !== '' ? $command->deviceId : 'unknown-device',
                $command->userId !== '' ? $command->userId : 'unknown-user',
            ),
            $location,
            $capturedAt,
            $command->idempotencyKey,
        );

        $this->evidence->save($evidence);

        $this->recordEvents->record($evidence->releaseEvents(), 'execution.delivery_evidence');
    }
}

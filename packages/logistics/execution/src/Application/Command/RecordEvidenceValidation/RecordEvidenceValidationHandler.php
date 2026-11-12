<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Command\RecordEvidenceValidation;

use DateTimeImmutable;
use Logistics\Execution\Domain\Contract\DeliveryEvidenceRepository;
use Logistics\Execution\Domain\Exception\ExecutionException;
use Logistics\Execution\Domain\Service\DocumentNumberMatcher;
use Logistics\Execution\Domain\ValueObject\DeliveryEvidenceId;
use Logistics\Execution\Domain\ValueObject\EvidenceValidationStatus;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Aplica el resultado de validación a una evidencia existente.
 *
 * Si viene `status` explícito, lo usa. Si no, y hay `ocrDocumentNumber`, cruza on-premise ese número
 * contra el documento del cliente de la evidencia (DocumentNumberMatcher) y deriva el estado. En
 * cualquier otro caso deja `review` (hay que mirarlo a mano). No toca la captura (auditoría inmutable).
 *
 * Si el resultado cambió, el agregado graba DeliveryEvidenceValidated y se encola al outbox en la MISMA
 * transacción del CommandBus (mismo patrón que la captura), para que compensaciones/otros reaccionen.
 *
 * @implements CommandHandler<RecordEvidenceValidation>
 */
final class RecordEvidenceValidationHandler implements CommandHandler
{
    public function __construct(
        private readonly DeliveryEvidenceRepository $evidence,
        private readonly DocumentNumberMatcher $matcher,
        private readonly RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        if (!$command instanceof RecordEvidenceValidation) {
            throw ExecutionException::because('RecordEvidenceValidationHandler solo maneja RecordEvidenceValidation');
        }

        $evidence = $this->evidence->findById(DeliveryEvidenceId::fromString($command->evidenceId));
        if ($evidence === null) {
            throw ExecutionException::because('no existe la evidencia a validar: '.$command->evidenceId);
        }

        $status = $this->resolveStatus($command, $evidence->customer()->documentId);

        $validatedAt = $command->validatedAt !== null && trim($command->validatedAt) !== ''
            ? new DateTimeImmutable($command->validatedAt)
            : new DateTimeImmutable;

        $evidence->applyValidation($status, $command->detail, $validatedAt, $command->validatedBy);

        $this->evidence->save($evidence);

        $this->recordEvents->record($evidence->releaseEvents(), 'execution.delivery_evidence');
    }

    private function resolveStatus(RecordEvidenceValidation $command, ?string $customerDocument): EvidenceValidationStatus
    {
        if ($command->status !== null && trim($command->status) !== '') {
            return EvidenceValidationStatus::fromCode($command->status);
        }

        if ($command->ocrDocumentNumber !== null && trim($command->ocrDocumentNumber) !== '') {
            return $this->matcher->match($customerDocument, $command->ocrDocumentNumber);
        }

        return EvidenceValidationStatus::Review;
    }
}

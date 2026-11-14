<?php

declare(strict_types=1);

namespace Logistics\Compensation\Application\Command\ApproveCompensationCase;

use Logistics\Compensation\Application\Contract\CustomerContactReader;
use Logistics\Compensation\Application\Service\VoucherIssuer;
use Logistics\Compensation\Domain\Contract\CaseAuditLog;
use Logistics\Compensation\Domain\Contract\CompensationCaseRepository;
use Logistics\Compensation\Domain\Exception\CompensationException;
use Logistics\Compensation\Domain\ValueObject\CompensationCaseId;
use Logistics\Compensation\Domain\ValueObject\Money;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;
use Logistics\Shared\Domain\Clock\Clock;

/**
 * Aprueba el caso (cuatro ojos y override a la baja los valida el agregado) y emite el vale en la misma
 * transacción. Idempotente a nivel vale: si el caso ya tenía vale, no crea otro.
 *
 * @implements CommandHandler<ApproveCompensationCase>
 */
final readonly class ApproveCompensationCaseHandler implements CommandHandler
{
    public function __construct(
        private CompensationCaseRepository $cases,
        private VoucherIssuer $voucherIssuer,
        private CustomerContactReader $customerContactReader,
        private RecordDomainEvents $recordEvents,
        private CaseAuditLog $auditLog,
        private Clock $clock,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof ApproveCompensationCase);

        $case = $this->cases->findById(CompensationCaseId::fromString($command->caseId));
        if ($case === null) {
            throw CompensationException::because('El caso de compensación no existe.');
        }

        $override = $command->overrideCents !== null
            ? Money::of($command->overrideCents, $case->effective()->currency)
            : null;

        $case->approve($command->approver, $this->clock->now(), $override);
        $case->markIssued();

        $this->cases->save($case);
        $this->recordEvents->record($case->releaseEvents(), 'compensation.case');
        $this->auditLog->record(
            $case->id()->toString(),
            null,
            'approved',
            $command->approver,
            ['effective_cents' => $case->effective()->cents, 'override' => $command->overrideCents !== null],
        );

        $email = $command->holderEmail
            ?? ($case->shipmentId() !== null ? $this->customerContactReader->emailForShipment($case->shipmentId()) : null);
        $this->voucherIssuer->issueFor($case, $email);
    }
}

<?php

declare(strict_types=1);

namespace Logistics\Compensation\Application\Command\IssueVoucher;

use Logistics\Compensation\Application\Contract\CustomerContactReader;
use Logistics\Compensation\Application\Service\VoucherIssuer;
use Logistics\Compensation\Domain\Contract\CompensationCaseRepository;
use Logistics\Compensation\Domain\Exception\CompensationException;
use Logistics\Compensation\Domain\ValueObject\CaseStatus;
use Logistics\Compensation\Domain\ValueObject\CompensationCaseId;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Emite el vale de un caso aprobado. Si el caso ya está emitido (tiene vale) es un no-op idempotente.
 *
 * @implements CommandHandler<IssueVoucher>
 */
final readonly class IssueVoucherHandler implements CommandHandler
{
    public function __construct(
        private CompensationCaseRepository $cases,
        private VoucherIssuer $voucherIssuer,
        private CustomerContactReader $customerContactReader,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof IssueVoucher);

        $case = $this->cases->findById(CompensationCaseId::fromString($command->caseId));
        if ($case === null) {
            throw CompensationException::because('El caso de compensación no existe.');
        }

        if ($case->status() === CaseStatus::Approved) {
            $case->markIssued();
            $this->cases->save($case);
            $this->recordEvents->record($case->releaseEvents(), 'compensation.case');
        } elseif ($case->status() !== CaseStatus::Issued) {
            throw CompensationException::because('Solo se puede emitir el vale de un caso aprobado.');
        }

        $email = $command->holderEmail
            ?? ($case->shipmentId() !== null ? $this->customerContactReader->emailForShipment($case->shipmentId()) : null);
        $this->voucherIssuer->issueFor($case, $email);
    }
}

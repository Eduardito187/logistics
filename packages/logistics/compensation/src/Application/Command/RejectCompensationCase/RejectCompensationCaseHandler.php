<?php

declare(strict_types=1);

namespace Logistics\Compensation\Application\Command\RejectCompensationCase;

use Logistics\Compensation\Domain\Contract\CaseAuditLog;
use Logistics\Compensation\Domain\Contract\CompensationCaseRepository;
use Logistics\Compensation\Domain\Exception\CompensationException;
use Logistics\Compensation\Domain\ValueObject\CompensationCaseId;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;
use Logistics\Shared\Domain\Clock\Clock;

/**
 * Rechaza el caso (el agregado valida cuatro ojos y que esté propuesto) y audita la decisión.
 *
 * @implements CommandHandler<RejectCompensationCase>
 */
final readonly class RejectCompensationCaseHandler implements CommandHandler
{
    public function __construct(
        private CompensationCaseRepository $cases,
        private RecordDomainEvents $recordEvents,
        private CaseAuditLog $auditLog,
        private Clock $clock,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof RejectCompensationCase);

        $case = $this->cases->findById(CompensationCaseId::fromString($command->caseId));
        if ($case === null) {
            throw CompensationException::because('El caso de compensación no existe.');
        }

        $case->reject($command->approver, $command->reason, $this->clock->now());

        $this->cases->save($case);
        $this->recordEvents->record($case->releaseEvents(), 'compensation.case');
        $this->auditLog->record(
            $case->id()->toString(),
            null,
            'rejected',
            $command->approver,
            ['reason' => $command->reason],
        );
    }
}

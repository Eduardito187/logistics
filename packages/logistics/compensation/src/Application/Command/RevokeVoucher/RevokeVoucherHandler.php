<?php

declare(strict_types=1);

namespace Logistics\Compensation\Application\Command\RevokeVoucher;

use Logistics\Compensation\Domain\Contract\CaseAuditLog;
use Logistics\Compensation\Domain\Contract\VoucherRepository;
use Logistics\Compensation\Domain\Exception\CompensationException;
use Logistics\Compensation\Domain\ValueObject\VoucherId;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Anula un vale (el agregado valida que no esté canjeado) y audita la decisión.
 *
 * @implements CommandHandler<RevokeVoucher>
 */
final readonly class RevokeVoucherHandler implements CommandHandler
{
    public function __construct(
        private VoucherRepository $vouchers,
        private RecordDomainEvents $recordEvents,
        private CaseAuditLog $auditLog,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof RevokeVoucher);

        $voucher = $this->vouchers->findById(VoucherId::fromString($command->voucherId));
        if ($voucher === null) {
            throw CompensationException::because('Vale no encontrado.');
        }

        $voucher->revoke($command->actor, $command->reason);

        $this->vouchers->save($voucher);
        $this->recordEvents->record($voucher->releaseEvents(), 'compensation.voucher');
        $this->auditLog->record(
            $voucher->caseId()->toString(),
            $voucher->id()->toString(),
            'revoked',
            $command->actor,
            ['reason' => $command->reason],
        );
    }
}

<?php

declare(strict_types=1);

namespace Logistics\Compensation\Application\Command\RedeemVoucher;

use Logistics\Compensation\Domain\Contract\CaseAuditLog;
use Logistics\Compensation\Domain\Contract\VoucherRepository;
use Logistics\Compensation\Domain\Exception\CompensationException;
use Logistics\Compensation\Domain\ValueObject\Money;
use Logistics\Compensation\Domain\ValueObject\VoucherCode;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;
use Logistics\Shared\Domain\Clock\Clock;

/**
 * Canje transaccional del vale. Toma el candado de fila (findByCodeForUpdate) para que dos canjes
 * concurrentes del mismo código no ganen los dos: uno canjea, el otro ve el estado ya cambiado y falla.
 *
 * El vale es nominativo (D2-6): si tiene titular y se pasa email, deben coincidir.
 *
 * @implements CommandHandler<RedeemVoucher>
 */
final readonly class RedeemVoucherHandler implements CommandHandler
{
    public function __construct(
        private VoucherRepository $vouchers,
        private RecordDomainEvents $recordEvents,
        private CaseAuditLog $auditLog,
        private Clock $clock,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof RedeemVoucher);

        $voucher = $this->vouchers->findByCodeForUpdate(VoucherCode::fromString($command->code));
        if ($voucher === null) {
            throw CompensationException::because('Vale no encontrado.');
        }

        if ($command->customerEmail !== null && ! $voucher->belongsTo($command->customerEmail)) {
            throw CompensationException::because('El vale pertenece a otro cliente.');
        }

        $voucher->redeem(
            $command->channel,
            $command->saleRef,
            Money::of($command->amountUsedCents, $voucher->amount()->currency),
            $this->clock->now(),
        );

        $this->vouchers->save($voucher);
        $this->recordEvents->record($voucher->releaseEvents(), 'compensation.voucher');
        $this->auditLog->record(
            $voucher->caseId()->toString(),
            $voucher->id()->toString(),
            'redeemed',
            $command->channel,
            ['sale_ref' => $command->saleRef, 'amount_used_cents' => $command->amountUsedCents],
        );
    }
}

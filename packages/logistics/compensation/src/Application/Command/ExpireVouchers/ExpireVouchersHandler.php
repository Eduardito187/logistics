<?php

declare(strict_types=1);

namespace Logistics\Compensation\Application\Command\ExpireVouchers;

use Logistics\Compensation\Domain\Contract\VoucherRepository;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;
use Logistics\Shared\Domain\Clock\Clock;

/**
 * Vence los vales emitidos cuya fecha ya pasó. Usa el índice parcial (status = 'issued'); cada vencimiento
 * emite `voucher_expired` para que el sync desactive la regla en Magento. Idempotente (un vale ya vencido
 * no se procesa de nuevo).
 *
 * @implements CommandHandler<ExpireVouchers>
 */
final readonly class ExpireVouchersHandler implements CommandHandler
{
    public function __construct(
        private VoucherRepository $vouchers,
        private RecordDomainEvents $recordEvents,
        private Clock $clock,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof ExpireVouchers);

        $now = $this->clock->now();
        foreach ($this->vouchers->idsExpiredAsOf($now, $command->limit) as $id) {
            $voucher = $this->vouchers->findById($id);
            if ($voucher === null || ! $voucher->expire($now)) {
                continue;
            }
            $this->vouchers->save($voucher);
            $this->recordEvents->record($voucher->releaseEvents(), 'compensation.voucher');
        }
    }
}

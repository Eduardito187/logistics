<?php

declare(strict_types=1);

namespace Logistics\Compensation\Application\Command\HoldVoucher;

use Logistics\Compensation\Domain\Contract\VoucherRepository;
use Logistics\Compensation\Domain\Exception\CompensationException;
use Logistics\Compensation\Domain\ValueObject\VoucherCode;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;
use Logistics\Shared\Domain\Clock\Clock;

/**
 * Reserva un vale para una venta del POS. Toma el candado de fila para no reservar dos ventas a la vez.
 *
 * @implements CommandHandler<HoldVoucher>
 */
final readonly class HoldVoucherHandler implements CommandHandler
{
    public function __construct(
        private VoucherRepository $vouchers,
        private Clock $clock,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof HoldVoucher);

        $voucher = $this->vouchers->findByCodeForUpdate(VoucherCode::fromString($command->code));
        if ($voucher === null) {
            throw CompensationException::because('Vale no encontrado.');
        }

        $now = $this->clock->now();
        $voucher->hold($command->channel, $now->modify("+{$command->ttlMinutes} minutes"), $now);
        $this->vouchers->save($voucher);
    }
}

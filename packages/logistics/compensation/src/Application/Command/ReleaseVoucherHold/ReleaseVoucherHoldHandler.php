<?php

declare(strict_types=1);

namespace Logistics\Compensation\Application\Command\ReleaseVoucherHold;

use Logistics\Compensation\Domain\Contract\VoucherRepository;
use Logistics\Compensation\Domain\Exception\CompensationException;
use Logistics\Compensation\Domain\ValueObject\VoucherCode;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Libera el hold de un vale (el POS canceló la venta antes de canjear).
 *
 * @implements CommandHandler<ReleaseVoucherHold>
 */
final readonly class ReleaseVoucherHoldHandler implements CommandHandler
{
    public function __construct(
        private VoucherRepository $vouchers,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof ReleaseVoucherHold);

        $voucher = $this->vouchers->findByCodeForUpdate(VoucherCode::fromString($command->code));
        if ($voucher === null) {
            throw CompensationException::because('Vale no encontrado.');
        }

        $voucher->releaseHold();
        $this->vouchers->save($voucher);
    }
}

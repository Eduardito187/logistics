<?php

declare(strict_types=1);

namespace Logistics\Compensation\Presentation\Console;

use Illuminate\Console\Command;
use Logistics\Compensation\Application\Command\ExpireVouchers\ExpireVouchers;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Cron diario: vence los vales emitidos cuya fecha ya pasó. Cada vencimiento emite `voucher_expired` por el
 * outbox (el sync desactiva la regla en Magento). Idempotente.
 */
final class ExpireVouchersCommand extends Command
{
    protected $signature = 'logistics:compensation:expire-vouchers {--limit=500 : Máximo de vales por corrida}';

    protected $description = 'Expire issued delivery-compensation vouchers whose validity has elapsed';

    public function handle(CommandBus $commandBus): int
    {
        $limit = (int) $this->option('limit');
        $commandBus->dispatch(new ExpireVouchers($limit > 0 ? $limit : 500));

        $this->info('Compensation vouchers expiry sweep dispatched.');

        return self::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace Commerce\Orders\Application\Command\CancelOrder;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de cancelar una orden (permitido en Pending, Paid o Fulfilling; idempotente — ADR 020).
 */
final readonly class CancelOrder implements Command
{
    /**
     * @param string      $orderId id de la orden a cancelar
     * @param string|null $reason  motivo opcional (p. ej. `unpaid_timeout` en la expiración por cron)
     * @param string      $source  origen: `pos`, `manual` (admin), `cron` (expiración), `customer`
     */
    public function __construct(
        public string $orderId,
        public ?string $reason = null,
        public string $source = 'manual',
    ) {}
}

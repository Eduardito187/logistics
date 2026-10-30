<?php

declare(strict_types=1);

namespace Commerce\Payments\Application\Query\GetPaymentByOrder;

use Logistics\Shared\Application\Query\Query;

/**
 * Pide el pago más reciente asociado a una orden (por su UUID).
 */
final readonly class GetPaymentByOrder implements Query
{
    public function __construct(
        public string $orderId,
    ) {}
}

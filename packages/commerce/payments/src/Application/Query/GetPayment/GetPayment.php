<?php

declare(strict_types=1);

namespace Commerce\Payments\Application\Query\GetPayment;

use Logistics\Shared\Application\Query\Query;

/**
 * Pide un pago por su id.
 */
final readonly class GetPayment implements Query
{
    public function __construct(
        public string $paymentId,
    ) {}
}

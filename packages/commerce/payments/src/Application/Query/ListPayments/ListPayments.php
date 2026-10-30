<?php

declare(strict_types=1);

namespace Commerce\Payments\Application\Query\ListPayments;

use Logistics\Shared\Application\Query\Query;

/**
 * Lista los pagos, con filtro opcional por estado (pending|authorized|captured|failed|refunded).
 */
final readonly class ListPayments implements Query
{
    public function __construct(
        public ?string $status = null,
    ) {}
}

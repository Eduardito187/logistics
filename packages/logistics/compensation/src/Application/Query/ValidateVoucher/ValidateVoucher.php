<?php

declare(strict_types=1);

namespace Logistics\Compensation\Application\Query\ValidateVoucher;

use Logistics\Shared\Application\Query\Query;

/**
 * Consulta de validez de un vale (POS / checkout): ¿es canjeable, por cuánto, hasta cuándo?
 */
final readonly class ValidateVoucher implements Query
{
    public function __construct(
        public string $code,
        public ?string $customerEmail = null,
    ) {}
}

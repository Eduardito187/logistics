<?php

declare(strict_types=1);

namespace Commerce\Orders\Application\Query\GetOrder;

use Logistics\Shared\Application\Query\Query;

/**
 * Consulta una orden por su id.
 */
final readonly class GetOrder implements Query
{
    public function __construct(
        public string $orderId,
    ) {}
}

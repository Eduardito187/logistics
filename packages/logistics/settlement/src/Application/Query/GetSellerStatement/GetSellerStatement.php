<?php

declare(strict_types=1);

namespace Logistics\Settlement\Application\Query\GetSellerStatement;

use Logistics\Shared\Application\Query\Query;

/**
 * Consulta el estado de cuenta (statement) agregado de un seller, opcionalmente acotado a un periodo.
 */
final readonly class GetSellerStatement implements Query
{
    public function __construct(
        public string $sellerId,
        public ?string $periodFrom = null,
        public ?string $periodTo = null,
    ) {}
}

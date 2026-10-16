<?php

declare(strict_types=1);

namespace Logistics\Inbound\Application\Query\GetGoodsReceipt;

use Logistics\Shared\Application\Query\Query;

/**
 * Pide la recepción (goods receipt) asociada a un ASN.
 */
final readonly class GetGoodsReceipt implements Query
{
    public function __construct(
        public string $asnId,
    ) {}
}

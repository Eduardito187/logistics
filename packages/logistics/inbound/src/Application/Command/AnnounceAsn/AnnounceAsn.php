<?php

declare(strict_types=1);

namespace Logistics\Inbound\Application\Command\AnnounceAsn;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de anunciar un ASN: un seller declara qué va a enviar a un almacén central.
 */
final readonly class AnnounceAsn implements Command
{
    /**
     * @param list<array{sku: string, expected_quantity: int}> $lines
     */
    public function __construct(
        public string $sellerId,
        public string $warehouseId,
        public array $lines,
    ) {}
}

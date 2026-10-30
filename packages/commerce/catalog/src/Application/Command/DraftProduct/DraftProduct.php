<?php

declare(strict_types=1);

namespace Commerce\Catalog\Application\Command\DraftProduct;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de dar de alta un producto nuevo en borrador.
 */
final readonly class DraftProduct implements Command
{
    public function __construct(
        public string $name,
        public string $canonicalSku,
        public ?string $sellerId = null,
        public ?string $description = null,
        public ?string $categoryId = null,
    ) {}
}

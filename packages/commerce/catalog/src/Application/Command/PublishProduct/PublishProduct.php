<?php

declare(strict_types=1);

namespace Commerce\Catalog\Application\Command\PublishProduct;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de publicar un producto (dejarlo visible y comprable).
 */
final readonly class PublishProduct implements Command
{
    public function __construct(
        public string $productId,
    ) {}
}

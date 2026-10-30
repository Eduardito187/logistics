<?php

declare(strict_types=1);

namespace Commerce\Catalog\Application\Command\CreateCategory;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de crear una categoría del catálogo (opcionalmente bajo una categoría padre).
 */
final readonly class CreateCategory implements Command
{
    public function __construct(
        public string $name,
        public string $slug,
        public ?string $parentId = null,
    ) {}
}

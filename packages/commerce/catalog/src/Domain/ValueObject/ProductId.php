<?php

declare(strict_types=1);

namespace Commerce\Catalog\Domain\ValueObject;

use Logistics\Shared\Domain\ValueObject\AbstractId;

/**
 * Identidad tipada de un producto del catálogo. Por dentro es un UUID v7 (ver AbstractId).
 */
final readonly class ProductId extends AbstractId {}

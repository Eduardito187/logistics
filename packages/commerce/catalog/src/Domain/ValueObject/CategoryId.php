<?php

declare(strict_types=1);

namespace Commerce\Catalog\Domain\ValueObject;

use Logistics\Shared\Domain\ValueObject\AbstractId;

/**
 * Identidad tipada de una categoría del catálogo. Por dentro es un UUID v7 (ver AbstractId).
 */
final readonly class CategoryId extends AbstractId {}

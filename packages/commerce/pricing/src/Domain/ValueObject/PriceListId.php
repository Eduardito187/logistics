<?php

declare(strict_types=1);

namespace Commerce\Pricing\Domain\ValueObject;

use Logistics\Shared\Domain\ValueObject\AbstractId;

/**
 * Identidad tipada de una lista de precios. Por dentro es un UUID v7 (ver AbstractId).
 */
final readonly class PriceListId extends AbstractId {}

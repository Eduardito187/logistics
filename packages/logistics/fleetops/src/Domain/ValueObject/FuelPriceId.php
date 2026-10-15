<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Domain\ValueObject;

use Logistics\Shared\Domain\ValueObject\AbstractId;

/**
 * Identidad tipada de un precio de combustible. Por dentro es un UUID v7 (ver AbstractId).
 */
final readonly class FuelPriceId extends AbstractId {}

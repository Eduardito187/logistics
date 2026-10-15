<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Domain\ValueObject;

use Logistics\Shared\Domain\ValueObject\AbstractId;

/**
 * Identidad tipada de una carga de combustible. Por dentro es un UUID v7 (ver AbstractId).
 */
final readonly class RefuelId extends AbstractId {}

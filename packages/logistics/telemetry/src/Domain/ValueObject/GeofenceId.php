<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Domain\ValueObject;

use Logistics\Shared\Domain\ValueObject\AbstractId;

/**
 * Identidad tipada de una geocerca. Por dentro es un UUID v7 (ver AbstractId).
 */
final readonly class GeofenceId extends AbstractId {}

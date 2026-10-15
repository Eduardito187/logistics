<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Domain\ValueObject;

use Logistics\Shared\Domain\ValueObject\AbstractId;

/**
 * Identidad tipada de un trip (tramo de conducción). Por dentro es un UUID v7 (ver AbstractId).
 */
final readonly class TripId extends AbstractId {}

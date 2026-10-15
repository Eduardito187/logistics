<?php

declare(strict_types=1);

namespace Logistics\Execution\Domain\ValueObject;

use Logistics\Shared\Domain\ValueObject\AbstractId;

/**
 * Identidad tipada de un incidente de camión reportado por el chofer. Por dentro es un UUID v7.
 */
final readonly class VehicleIncidentId extends AbstractId {}

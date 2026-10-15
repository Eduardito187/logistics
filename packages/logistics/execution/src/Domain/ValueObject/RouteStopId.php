<?php

declare(strict_types=1);

namespace Logistics\Execution\Domain\ValueObject;

use Logistics\Shared\Domain\ValueObject\AbstractId;

/**
 * Identidad tipada de una parada de la ruta del chofer. Por dentro es un UUID v7.
 */
final readonly class RouteStopId extends AbstractId {}

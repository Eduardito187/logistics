<?php

declare(strict_types=1);

namespace Logistics\Execution\Domain\ValueObject;

use Logistics\Shared\Domain\ValueObject\AbstractId;

/**
 * Identidad tipada de la ruta del chofer (una por camión y día). Por dentro es un UUID v7.
 */
final readonly class RouteId extends AbstractId {}

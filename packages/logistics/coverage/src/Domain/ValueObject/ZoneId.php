<?php

declare(strict_types=1);

namespace Logistics\Coverage\Domain\ValueObject;

use Logistics\Shared\Domain\ValueObject\AbstractId;

/**
 * Identificador tipado de una zona de cobertura. Por dentro es un UUID v7.
 */
final readonly class ZoneId extends AbstractId {}

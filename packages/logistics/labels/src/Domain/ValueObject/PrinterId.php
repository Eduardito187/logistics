<?php

declare(strict_types=1);

namespace Logistics\Labels\Domain\ValueObject;

use Logistics\Shared\Domain\ValueObject\AbstractId;

/**
 * Identidad (UUID v7) de una impresora de red registrada.
 */
final readonly class PrinterId extends AbstractId {}

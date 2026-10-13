<?php

declare(strict_types=1);

namespace Logistics\Shipments\Domain\ValueObject;

use Logistics\Shared\Domain\ValueObject\AbstractId;

/**
 * Identidad tipada de una unidad física (bulto): un bulto por unidad de producto.
 */
final readonly class PhysicalUnitId extends AbstractId {}

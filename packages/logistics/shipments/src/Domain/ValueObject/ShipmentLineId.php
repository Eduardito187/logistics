<?php

declare(strict_types=1);

namespace Logistics\Shipments\Domain\ValueObject;

use Logistics\Shared\Domain\ValueObject\AbstractId;

/**
 * Identidad tipada de una línea del envío (un producto con su cantidad).
 */
final readonly class ShipmentLineId extends AbstractId {}

<?php

declare(strict_types=1);

namespace Logistics\Shipments\Domain\ValueObject;

use Logistics\Shared\Domain\ValueObject\AbstractId;

/**
 * Identidad tipada del envío. Por dentro es un UUID v7 (ver núcleo compartido).
 */
final readonly class ShipmentId extends AbstractId {}

<?php

declare(strict_types=1);

namespace Logistics\Carriers\Domain\ValueObject;

use Logistics\Shared\Domain\ValueObject\AbstractId;

/**
 * Identidad tipada de un viaje de transportista (carga asignada). Por dentro es un UUID v7 (ver AbstractId).
 */
final readonly class CarrierTripId extends AbstractId {}

<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Domain\ValueObject;

use Logistics\Shared\Domain\ValueObject\AbstractId;

/**
 * Identidad tipada de una lectura de odómetro. Por dentro es un UUID v7 (ver AbstractId).
 */
final readonly class OdometerReadingId extends AbstractId {}

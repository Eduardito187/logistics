<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Domain\ValueObject;

use Logistics\Shared\Domain\ValueObject\AbstractId;

/**
 * Identidad tipada de un plan de mantenimiento preventivo. Por dentro es un UUID v7 (ver AbstractId).
 */
final readonly class MaintenancePlanId extends AbstractId {}

<?php

declare(strict_types=1);

namespace Logistics\Planning\Domain\ValueObject;

use Logistics\Shared\Domain\ValueObject\AbstractId;

/**
 * Identidad tipada del plan de reparto. Por dentro es un UUID v7 (ver núcleo compartido).
 */
final readonly class PlanId extends AbstractId {}

<?php

declare(strict_types=1);

namespace Logistics\Planning\Domain\ValueObject;

use Logistics\Shared\Domain\ValueObject\AbstractId;

/**
 * Identidad tipada de una corrida del auto-planificador diario. Por dentro es un UUID v7.
 */
final readonly class AutoPlanRunId extends AbstractId {}

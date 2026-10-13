<?php

declare(strict_types=1);

namespace Logistics\Planning\Application\Command\PublishPlan;

use Logistics\Shared\Application\Command\Command;

/**
 * Publica el plan indicado: lo confirma y lo deja disponible para que la ejecución lo tome.
 */
final readonly class PublishPlan implements Command
{
    public function __construct(
        public string $planId,
    ) {}
}

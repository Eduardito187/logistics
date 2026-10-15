<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Application\Query\ListGeofences;

use Logistics\Shared\Application\Query\Query;

/**
 * Lista las geocercas. Opcionalmente filtra por tipo.
 */
final readonly class ListGeofences implements Query
{
    public function __construct(
        public ?string $type = null,
    ) {}
}

<?php

declare(strict_types=1);

namespace Logistics\Carriers\Application\Query\ListCarriers;

use Logistics\Shared\Application\Query\Query;

/**
 * Lista los transportistas. Opcionalmente filtra por estado.
 */
final readonly class ListCarriers implements Query
{
    public function __construct(
        public ?string $status = null,
    ) {}
}

<?php

declare(strict_types=1);

namespace Logistics\Fleet\Application\Query\ListHelpers;

use Logistics\Shared\Application\Query\Query;

/**
 * Lista los ayudantes de la flota. Opcionalmente filtra por disponibilidad (solo activos).
 */
final readonly class ListHelpers implements Query
{
    public function __construct(
        public ?bool $onlyActive = null,
    ) {}
}

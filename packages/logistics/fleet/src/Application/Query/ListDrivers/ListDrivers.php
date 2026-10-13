<?php

declare(strict_types=1);

namespace Logistics\Fleet\Application\Query\ListDrivers;

use Logistics\Shared\Application\Query\Query;

/**
 * Lista los choferes de la flota. Opcionalmente filtra por estado activo.
 */
final readonly class ListDrivers implements Query
{
    public function __construct(
        public ?bool $onlyActive = null,
    ) {}
}

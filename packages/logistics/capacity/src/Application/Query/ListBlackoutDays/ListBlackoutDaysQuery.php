<?php

declare(strict_types=1);

namespace Logistics\Capacity\Application\Query\ListBlackoutDays;

use Logistics\Shared\Application\Query\Query;

/**
 * Lista los días bloqueados de despacho dentro de un rango (ambos extremos incluidos). Si se pasa un
 * municipio, incluye sus bloqueos y los globales que lo afectan; si no, todos los del rango.
 */
final readonly class ListBlackoutDaysQuery implements Query
{
    public function __construct(
        public string $from,
        public string $to,
        public ?string $municipalityId = null,
    ) {}
}

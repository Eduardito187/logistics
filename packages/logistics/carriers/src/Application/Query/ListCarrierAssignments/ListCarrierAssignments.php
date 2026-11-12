<?php

declare(strict_types=1);

namespace Logistics\Carriers\Application\Query\ListCarrierAssignments;

use Logistics\Shared\Application\Query\Query;

/**
 * Viajes asignados (con su carga) de un transportista, o de todos si `carrierId` es null (oficina).
 *
 * `statuses` vacío = solo los vigentes (programados y en ruta), que es lo que el transportista opera.
 */
final readonly class ListCarrierAssignments implements Query
{
    /**
     * @param list<string> $statuses
     */
    public function __construct(
        public ?string $carrierId,
        public array $statuses = [],
        public int $limit = 50,
        public int $offset = 0,
    ) {}
}

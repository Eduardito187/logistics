<?php

declare(strict_types=1);

namespace Logistics\Planning\Application\ReadModel;

use Logistics\Shipments\Application\Scope\ShipmentScopeActor;

/**
 * Criterio de búsqueda de los pedidos candidatos a un viaje, en primitivos, tal como lo recibe el lector.
 *
 * - `date`: día del viaje ('Y-m-d').
 * - `includeUndated`: true cuando el día es HOY: suma los pedidos sin fecha prometida y los atrasados (la
 *   misma regla de fecha del auto-planificador).
 * - `department`: código de departamento de la base del camión; null = sin filtro geográfico.
 * - `anchorLatitude` / `anchorLongitude`: punto desde el que se ordena por cercanía; null = sin distancia.
 * - `excludedShipmentIds`: lo ya elegido para el viaje (no se vuelve a ofrecer).
 * - `scope`: alcance por rol del usuario (regla del contexto Envíos); null = sin alcance.
 * - `limit` / `offset`: página a devolver.
 */
final readonly class TripCandidateCriteria
{
    /**
     * @param list<string> $excludedShipmentIds
     */
    public function __construct(
        public string $date,
        public bool $includeUndated,
        public ?string $department,
        public ?float $anchorLatitude,
        public ?float $anchorLongitude,
        public array $excludedShipmentIds,
        public ?string $search,
        public ?ShipmentScopeActor $scope,
        public int $limit,
        public int $offset = 0,
    ) {}
}

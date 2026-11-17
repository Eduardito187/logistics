<?php

declare(strict_types=1);

namespace Logistics\Planning\Application\Query\ListPlansByDate;

use Logistics\Shared\Application\Query\Query;

/**
 * Lista los planes de reparto de un día (formato 'Y-m-d') o de un rango de días.
 *
 * Todos los parámetros nuevos son opcionales y aditivos (no rompen a quien solo pasa el día):
 *   - `dateTo`    → último día del rango (inclusive); null = solo `planDate`. Acotado a MAX_RANGE_DAYS.
 *   - `statuses`  → filtra por uno o VARIOS estados del plan (DRAFT|PROPOSED|PUBLISHED); [] = todos.
 *   - `search`    → texto libre (`q`): filtra por placa, nombre del chofer o nombre del nodo de origen.
 *   - `vehicleId` → filtra por camión.
 *   - `limit`/`offset` → si `limit` no es null, el handler devuelve un Page<PlanView> (página + total)
 *     en lugar de list<PlanView>; así Presentation arma el envelope estándar `data` + `meta`.
 */
final readonly class ListPlansByDate implements Query
{
    /** Máximo de días que abarca un rango (evita bajar el histórico entero en una sola consulta). */
    public const int MAX_RANGE_DAYS = 31;

    /**
     * Estados filtrables (espejo de los valores de PlanStatus) para que Presentation valide sin tocar Domain.
     *
     * @var list<string>
     */
    public const array STATUSES = ['DRAFT', 'PROPOSED', 'PUBLISHED'];

    /**
     * @param list<string> $statuses estados del plan a incluir ([] = todos)
     */
    public function __construct(
        public string $planDate,
        public ?string $dateTo = null,
        public array $statuses = [],
        public ?string $search = null,
        public ?string $vehicleId = null,
        public ?int $limit = null,
        public int $offset = 0,
    ) {}
}

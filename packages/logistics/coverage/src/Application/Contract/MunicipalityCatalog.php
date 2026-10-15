<?php

declare(strict_types=1);

namespace Logistics\Coverage\Application\Contract;

use Logistics\Coverage\Application\ReadModel\MunicipalityView;

/**
 * Lado de lectura (CQRS) del catálogo de municipios.
 *
 * Expone los municipios ya resueltos con su departamento para poblar selects del admin y respuestas de
 * API, sin pasar por el agregado ni la geometría. La implementación (Infrastructure) resuelve el
 * departamento en una sola consulta, pensada para miles de municipios sin N+1.
 */
interface MunicipalityCatalog
{
    /**
     * Todos los municipios, ordenados por nombre, con su departamento resuelto.
     *
     * @return array<int, MunicipalityView>
     */
    public function all(): array;
}

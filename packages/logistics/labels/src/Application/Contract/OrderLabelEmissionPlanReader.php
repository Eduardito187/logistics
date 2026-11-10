<?php

declare(strict_types=1);

namespace Logistics\Labels\Application\Contract;

use Logistics\Labels\Application\ReadModel\OrderLabelEmissionGroup;

/**
 * Puerto de lectura del PLAN DE EMISIÓN por orden: devuelve un grupo por envío hermano (multishipping) con
 * su origen/destino derivados, sus líneas y las banderas de autorización/emisión. Alimenta la emisión por
 * orden (body vacío): el backend deriva todo del envío y del plan publicado, sin que la app cargue nada.
 */
interface OrderLabelEmissionPlanReader
{
    /**
     * @return list<OrderLabelEmissionGroup>
     */
    public function emissionPlan(string $orderNumber): array;
}

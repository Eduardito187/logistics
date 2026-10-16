<?php

declare(strict_types=1);

namespace Logistics\Settlement\Domain\Contract;

use Logistics\Settlement\Domain\ValueObject\SettlementLine;
use Logistics\Settlement\Domain\ValueObject\SettlementPeriod;

/**
 * Puerto de LECTURA cross-context de los despachos de un seller en un periodo.
 *
 * El contexto `settlement` no depende por composer de `shipments`/`execution`: la implementación
 * (Infrastructure) lee esas tablas en modo solo lectura y EN LOTE (patrón del skill para analítica
 * cross-context). Devuelve una línea por despacho despachado/entregado del seller en el rango.
 */
interface SellerDispatchReader
{
    /**
     * @return list<SettlementLine>
     */
    public function dispatchesForSeller(string $sellerId, SettlementPeriod $period, string $currency): array;
}

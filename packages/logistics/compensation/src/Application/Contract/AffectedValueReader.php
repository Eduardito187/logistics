<?php

declare(strict_types=1);

namespace Logistics\Compensation\Application\Contract;

use Logistics\Compensation\Domain\ValueObject\AffectedValue;
use Logistics\Compensation\Domain\ValueObject\CompensationScope;

/**
 * Puerto de lectura del valor afectado de un envío (líneas con precio + base de la orden), SIN acoplar al
 * contexto de envíos: la implementación consulta las proyecciones de shipments. Devuelve null cuando no hay
 * base calculable (p. ej. líneas sin `unit_price` o en moneda ≠ BOB): regla 6 ⇒ solo emisión manual.
 */
interface AffectedValueReader
{
    /**
     * @param list<string> $affectedSkus SKUs afectados (para alcance LINE); vacío = todas las líneas
     */
    public function affectedForShipment(
        string $shipmentId,
        CompensationScope $scope,
        array $affectedSkus = [],
    ): ?AffectedValue;
}

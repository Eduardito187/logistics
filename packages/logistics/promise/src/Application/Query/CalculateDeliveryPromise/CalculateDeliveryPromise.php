<?php

declare(strict_types=1);

namespace Logistics\Promise\Application\Query\CalculateDeliveryPromise;

use Logistics\Shared\Application\Query\Query;

/**
 * Consulta el cálculo integrado de la promesa de entrega: para una zona (y opcionalmente una fecha de
 * inicio), devuelve las fechas y franjas que se pueden prometer combinando cobertura, capacidad y
 * corredor. Es la consulta que el canal usa para ofrecerle al cliente las opciones reales de entrega.
 */
final readonly class CalculateDeliveryPromise implements Query
{
    /**
     * Los campos de propiedad son ADITIVOS y opcionales: sin ellos la consulta es retail (OWNED) y se
     * comporta exactamente como siempre. Cuando el ítem es de un seller (FBD/DROPSHIP), el handler arma el
     * `Ownership` con ellos y le suma a la promesa el lead time del modo de fulfillment.
     *
     * @param string      $zoneId          zona de cobertura (id primitivo)
     * @param string|null $fromDate        primer día del horizonte (Y-m-d); null = desde hoy
     * @param int         $horizonDays     cuántos días hacia adelante se exploran (incluye el día de inicio)
     * @param string|null $fulfillmentMode modo de fulfillment ('owned'|'fbd'|'dropship'); null = retail (owned)
     * @param string|null $ownerPartyId    seller dueño del producto (uuid); obligatorio en fbd/dropship
     * @param string|null $sellerSkuRef    SKU del seller (opcional), para un lead por seller/sku a futuro
     */
    public function __construct(
        public string $zoneId,
        public ?string $fromDate = null,
        public int $horizonDays = 14,
        public ?string $fulfillmentMode = null,
        public ?string $ownerPartyId = null,
        public ?string $sellerSkuRef = null,
    ) {}
}

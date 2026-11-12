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
     * Los campos de propiedad se conservan solo por compatibilidad con llamadores existentes y el handler
     * los IGNORA: la promesa nunca depende del stock ni del tipo de propiedad (OWNED/FBD/DROPSHIP). Todo
     * pedido recibe el mismo cálculo para la misma zona, fecha y horizonte.
     *
     * @param string      $zoneId          zona de cobertura (id primitivo)
     * @param string|null $fromDate        primer día del horizonte (Y-m-d); null = desde hoy
     * @param int         $horizonDays     cuántos días hacia adelante se exploran (incluye el día de inicio)
     * @param string|null $fulfillmentMode modo de fulfillment ('owned'|'fbd'|'dropship'); ignorado
     * @param string|null $ownerPartyId    seller dueño del producto (uuid); ignorado
     * @param string|null $sellerSkuRef    SKU del seller; ignorado
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

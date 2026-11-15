<?php

declare(strict_types=1);

namespace Logistics\Shipments\Application\Query\CheckShipmentScope;

use Logistics\Shared\Application\Query\Query;
use Logistics\Shipments\Application\Scope\ShipmentScopeActor;

/**
 * ¿Puede este actor OPERAR el pedido o los envíos indicados? Es la guarda de las escrituras de operación
 * (emitir etiquetas, asignar vehículo, escaneos, entrega…). Devuelve un ShipmentScopeDecision.
 *
 *  - `orderNumber`: pedido completo. Se permite si AL MENOS UNO de sus envíos está en el alcance (un pedido
 *    con varios destinos lo operan las ciudades de cada destino además de la de origen).
 *  - `shipmentIds`: envíos puntuales. Cada uno que exista debe estar en el alcance.
 * Lo que no existe no se rechaza acá: sigue de largo para que el endpoint responda su 404 / 422 habitual.
 */
final readonly class CheckShipmentScope implements Query
{
    /**
     * @param list<string> $shipmentIds
     */
    public function __construct(
        public ShipmentScopeActor $actor,
        public ?string $orderNumber = null,
        public array $shipmentIds = [],
    ) {}
}

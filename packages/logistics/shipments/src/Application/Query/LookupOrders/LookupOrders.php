<?php

declare(strict_types=1);

namespace Logistics\Shipments\Application\Query\LookupOrders;

use Logistics\Shared\Application\Query\Query;
use Logistics\Shipments\Application\Scope\ShipmentScopeActor;

/**
 * Consulta abierta de pedidos (solo lectura, SIN alcance por rol): busca por código de pedido o por el
 * identificador de una etiqueta (y, para oficina y tienda, por nombre o documento del cliente) y devuelve los envíos del pedido con su avance, su seguimiento resumido y si
 * quien consulta puede operarlos. Devuelve un OrderLookupResult (vacío si no hay coincidencias).
 */
final readonly class LookupOrders implements Query
{
    /** Tope de envíos por consulta (lista corta). */
    public const MAX_RESULTS = 10;

    public function __construct(
        public string $term,
        // Quién consulta: NO limita lo que se ve (la consulta es abierta), solo calcula `can_process`.
        // Null = sin restricción.
        public ?ShipmentScopeActor $actor = null,
        // ¿La coincidencia parcial de texto también mira el NOMBRE y el DOCUMENTO del cliente? Solo para
        // oficina y tienda (lo decide la puerta según el rol). En false el texto solo coincide con el código
        // de pedido o la referencia de orden. No limita qué pedidos se pueden consultar ni qué se devuelve.
        public bool $searchCustomerData = false,
    ) {}
}

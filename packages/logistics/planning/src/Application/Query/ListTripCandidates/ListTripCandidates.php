<?php

declare(strict_types=1);

namespace Logistics\Planning\Application\Query\ListTripCandidates;

use Logistics\Shared\Application\Query\Query;
use Logistics\Shipments\Application\Scope\ShipmentScopeActor;

/**
 * Pedidos candidatos para armar el viaje de un camión en un día, ordenados por cercanía al último pedido
 * ya elegido (o a la base del camión si todavía no se eligió ninguno), con la indicación de si entran en
 * lo que le queda al camión y si el backend recomienda sumarlos ahora.
 *
 * Devuelve un TripCandidatesView, o null si el camión no existe.
 *
 * - `selectedShipmentIds`: lo ya elegido, EN ORDEN (el último es el punto de referencia).
 * - `departAt`: hora de salida en ISO-8601; null = la hora por defecto del día.
 * - `limit`: null = sin paginar (se devuelven los más cercanos hasta el tope configurado).
 * - `scope`: alcance por rol de quien consulta; lo fija la puerta HTTP desde el usuario autenticado.
 */
final readonly class ListTripCandidates implements Query
{
    /**
     * @param list<string> $selectedShipmentIds
     */
    public function __construct(
        public string $date,
        public string $vehicleId,
        public array $selectedShipmentIds = [],
        public ?string $search = null,
        public ?string $departAt = null,
        public ?int $limit = null,
        public int $offset = 0,
        public ?ShipmentScopeActor $scope = null,
    ) {}
}

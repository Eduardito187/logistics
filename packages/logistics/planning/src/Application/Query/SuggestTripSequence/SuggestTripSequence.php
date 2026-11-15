<?php

declare(strict_types=1);

namespace Logistics\Planning\Application\Query\SuggestTripSequence;

use Logistics\Shared\Application\Query\Query;

/**
 * Pide el ORDEN de reparto que recomienda el backend para un viaje armado a mano: saliendo de la base del
 * camión, el camino más corto que llega a cada pedido dentro de la hora que eligió su cliente, con la ETA
 * por parada y los avisos. Es una consulta: no guarda nada.
 *
 * Devuelve un TripSequenceSuggestionView, o null si el camión no existe.
 *
 * - `shipmentIds`: los pedidos del viaje en el orden en que los tiene la app.
 * - `departAt`: hora de salida en ISO-8601; null = la hora por defecto del día.
 */
final readonly class SuggestTripSequence implements Query
{
    /**
     * @param list<string> $shipmentIds
     */
    public function __construct(
        public string $date,
        public string $vehicleId,
        public array $shipmentIds,
        public ?string $departAt = null,
    ) {}
}

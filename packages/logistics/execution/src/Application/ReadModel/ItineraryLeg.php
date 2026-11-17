<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\ReadModel;

/**
 * Modelo de lectura de UN tramo del itinerario de un envío, visto por la ejecución (solo los datos que la
 * ejecución necesita para decidir si un nodo cierra el envío o solo el tramo). Solo primitivos.
 *
 * La ejecución NO se acopla al ReadModel de shipments: lee la tabla `shipment_legs` por su propio adapter y
 * devuelve este VO reducido. `toNodeId` null en el ÚLTIMO tramo = entrega a domicilio (sin nodo destino).
 */
final readonly class ItineraryLeg
{
    public function __construct(
        public int $seq,
        public ?string $fromNodeId,
        public ?string $toNodeId,
        public string $haulageMode,
        public ?string $carrierId,
        public string $status,
    ) {}
}

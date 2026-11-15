<?php

declare(strict_types=1);

namespace Logistics\Planning\Application\Contract;

use Logistics\Planning\Application\ReadModel\TripCandidateCriteria;
use Logistics\Shared\Application\ReadModel\Page;

/**
 * Puerto de LECTURA de los pedidos CANDIDATOS a un viaje: planificables ese día, sin camión ni plan
 * asignado, del departamento de la base del camión y dentro del alcance del usuario, ordenados por
 * cercanía a un punto. El adaptador de Infrastructure lo resuelve en SQL (filtro, orden y página en la
 * base; nunca una consulta por pedido ni una llamada al ruteador por candidato).
 */
interface TripCandidateReader
{
    /**
     * Página de candidatos: id del envío y distancia en metros al punto de referencia (null si el envío
     * no tiene ubicación o si no se indicó un punto). Los más cercanos primero; sin ubicación, al final.
     *
     * @return Page<array{shipment_id: string, distance_meters: float|null}>
     */
    public function candidates(TripCandidateCriteria $criteria): Page;
}

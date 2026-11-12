<?php

declare(strict_types=1);

namespace Logistics\Carriers\Application\Contract;

use Logistics\Carriers\Application\ReadModel\CarrierCorridorView;

/**
 * Lado de lectura (CQRS) de los corredores tarifados por los transportistas.
 *
 * Los corredores salen de las tarifas (`carrier_tariffs`): cada combinación distinta origen → destino +
 * tipo es un corredor, con las clases de vehículo tarifadas en él. Origen y destino son los MISMOS slugs
 * que acepta el endpoint de tarifa (p. ej. `cd-santa-cruz`); la etiqueta legible se resuelve aparte.
 */
interface CarrierCorridorReader
{
    /**
     * Corredores distintos ordenados por tipo, origen y destino.
     *
     * @param  string|null               $carrierId null = todos los transportistas (oficina)
     * @param  int|null                  $limit     null = sin recorte
     * @return list<CarrierCorridorView>
     */
    public function corridors(?string $carrierId, ?int $limit = null, ?int $offset = null): array;

    /**
     * Total de corredores distintos con el MISMO filtro que `corridors` (para la paginación).
     */
    public function count(?string $carrierId): int;
}

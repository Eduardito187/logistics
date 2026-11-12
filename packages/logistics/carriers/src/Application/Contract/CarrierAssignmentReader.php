<?php

declare(strict_types=1);

namespace Logistics\Carriers\Application\Contract;

use Logistics\Carriers\Application\ReadModel\CarrierAssignmentView;

/**
 * Lado de lectura (CQRS) de la carga asignada a los transportistas.
 *
 * Une los viajes de este contexto con datos de lectura de otros contextos (nombres de nodos de network,
 * envíos/líneas/unidades de shipments) SIN importar sus agregados: la implementación (Infrastructure)
 * consulta las tablas en lote (sin N+1). Si el contexto se extrae a servicio, esto pasa a ser una
 * proyección alimentada por eventos, sin cambiar a quien lo consume.
 */
interface CarrierAssignmentReader
{
    /**
     * Página de viajes con su carga completa, ordenada por fecha programada (asc) y id.
     *
     * @param  string|null                 $carrierId null = todos los transportistas (oficina)
     * @param  list<string>                $statuses  estados a incluir (vacío = todos)
     * @return list<CarrierAssignmentView>
     */
    public function assignments(?string $carrierId, array $statuses, int $limit, int $offset): array;

    /**
     * Total de viajes con los MISMOS filtros que `assignments` (para la paginación).
     *
     * @param list<string> $statuses
     */
    public function count(?string $carrierId, array $statuses): int;

    /**
     * De los ids dados, los que existen como envío.
     *
     * @param  list<string> $shipmentIds
     * @return list<string>
     */
    public function existingShipmentIds(array $shipmentIds): array;
}

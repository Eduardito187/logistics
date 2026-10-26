<?php

declare(strict_types=1);

namespace Logistics\Shipments\Application\Contract;

use Logistics\Shipments\Application\ReadModel\ShipmentView;

/**
 * Lado de LECTURA (CQRS) del listado de envíos: empuja los filtros calientes a SQL y construye los
 * ShipmentView directamente desde las filas, SIN reconstituir el agregado ni cargar la tabla entera en
 * memoria. El contrato vive en Application (devuelve un ReadModel de Application); la implementación con
 * query builder vive en Infrastructure, de modo que el Domain queda intacto.
 *
 * Reemplaza el patrón previo (ShipmentRepository::all() + filtrado en PHP), que materializaba todos los
 * agregados —con sus líneas y bultos— para descartarlos luego: a escala de millones de filas eso no es
 * viable. Acá cada filtro es un predicado indexable en la base.
 */
interface ShipmentListReader
{
    /**
     * Lista los envíos que cumplen los filtros, ya como ShipmentView.
     *
     * Todos los filtros son opcionales y se combinan con AND. `$status` y `$source` reciben el valor del
     * enum ya validado (string) o null; `$destinationNodeId` el UUID del nodo o null; `$lowConfidenceOnly`
     * acota a las direcciones de confianza baja. `$limit`/`$offset` son opcionales (null = sin recorte):
     * permiten paginar en SQL cuando el consumidor lo pida, sin cambiar la forma del resultado.
     *
     * El orden es estable por `id` (UUID v7, orden temporal), igual que el listado histórico.
     *
     * @return list<ShipmentView>
     */
    public function list(
        ?string $status = null,
        ?string $source = null,
        ?string $destinationNodeId = null,
        bool $lowConfidenceOnly = false,
        ?int $limit = null,
        ?int $offset = null,
    ): array;

    /**
     * Cuenta los envíos que cumplen los MISMOS filtros (sin recorte), para la paginación: `total` del
     * envelope sale de este COUNT(*) y no de materializar la tabla. Los filtros se combinan con AND,
     * igual que en `list()`.
     */
    public function count(
        ?string $status = null,
        ?string $source = null,
        ?string $destinationNodeId = null,
        bool $lowConfidenceOnly = false,
    ): int;
}

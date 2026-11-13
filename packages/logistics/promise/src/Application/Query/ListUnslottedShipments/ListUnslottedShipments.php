<?php

declare(strict_types=1);

namespace Logistics\Promise\Application\Query\ListUnslottedShipments;

use Logistics\Shared\Application\Query\Query;

/**
 * Cola "Unslotted" (Módulo 1, Fase 1.1): envíos con fecha de entrega solicitada pero AÚN sin ventana
 * asignada, para que operaciones los resuelva (auto-slot, elegir franja o mover fecha). `$limit` acota el
 * lote devuelto.
 */
final readonly class ListUnslottedShipments implements Query
{
    public function __construct(
        public int $limit = 100,
    ) {}
}

<?php

declare(strict_types=1);

namespace Logistics\Carriers\Application\Query\ListCarrierCorridors;

use Logistics\Shared\Application\Query\Query;

/**
 * Corredores tarifados de un transportista (o de todos si `carrierId` es null: oficina), armados a partir
 * de sus tarifas: corredores distintos con las clases de vehículo tarifadas en cada uno.
 *
 * `limit` null = sin paginar (lista completa); con `limit` el handler devuelve una Page recortada en SQL.
 */
final readonly class ListCarrierCorridors implements Query
{
    public function __construct(
        public ?string $carrierId = null,
        public ?int $limit = null,
        public ?int $offset = null,
    ) {}
}

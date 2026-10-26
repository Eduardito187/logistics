<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Domain\Contract;

use DateTimeImmutable;
use Logistics\FleetOps\Domain\Model\Refuel;
use Logistics\FleetOps\Domain\ValueObject\RefuelId;

/**
 * Puerto de persistencia de cargas de combustible. La implementación vive en Infrastructure.
 */
interface RefuelRepository
{
    public function nextIdentity(): RefuelId;

    public function save(Refuel $refuel): void;

    public function findById(RefuelId $id): ?Refuel;

    /**
     * Cargas filtradas por vehículo y/o rango de fechas (cualquiera opcional), ordenadas por fecha desc.
     *
     * `$limit`/`$offset` son opcionales (null = sin recorte): bajan LIMIT/OFFSET a SQL al paginar.
     *
     * @return list<Refuel>
     */
    public function listBy(?string $vehicleId, ?DateTimeImmutable $from, ?DateTimeImmutable $to, ?int $limit = null, ?int $offset = null): array;

    /**
     * Cuenta las cargas que cumplen los MISMOS filtros que `listBy`, para el total de la paginación.
     */
    public function countBy(?string $vehicleId, ?DateTimeImmutable $from, ?DateTimeImmutable $to): int;

    /**
     * Todas las cargas de un vehículo ordenadas por fecha ascendente: base del análisis de consumo
     * (km recorridos y litros entre dos llenados a tanque lleno consecutivos).
     *
     * @return list<Refuel>
     */
    public function forVehicleOrdered(string $vehicleId): array;
}

<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Domain\Contract;

use DateTimeImmutable;
use Logistics\FleetOps\Domain\Model\Departure;
use Logistics\FleetOps\Domain\ValueObject\DepartureId;

/**
 * Puerto de persistencia de salidas/despachos. La implementación vive en Infrastructure.
 */
interface DepartureRepository
{
    public function nextIdentity(): DepartureId;

    public function save(Departure $departure): void;

    public function findById(DepartureId $id): ?Departure;

    /**
     * Lista salidas con filtros opcionales: por vehículo, por estado ('out'|'returned') y por rango de la
     * fecha de salida.
     *
     * @return list<Departure>
     */
    public function listBy(
        ?string $vehicleId = null,
        ?string $status = null,
        ?DateTimeImmutable $from = null,
        ?DateTimeImmutable $to = null,
    ): array;

    /**
     * Salida en ruta (Out) del vehículo, si existe. Sirve para impedir dos salidas abiertas a la vez.
     */
    public function openForVehicle(string $vehicleId): ?Departure;
}

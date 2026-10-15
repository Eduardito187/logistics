<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Domain\Contract;

use DateTimeImmutable;
use Logistics\FleetOps\Domain\Model\OdometerReading;
use Logistics\FleetOps\Domain\ValueObject\OdometerReadingId;

/**
 * Puerto de persistencia de lecturas de odómetro. La implementación vive en Infrastructure.
 */
interface OdometerRepository
{
    public function nextIdentity(): OdometerReadingId;

    public function save(OdometerReading $reading): void;

    /**
     * Última lectura (la más reciente por fecha de lectura) de un vehículo, si existe.
     */
    public function latestForVehicle(string $vehicleId): ?OdometerReading;

    /**
     * Lista lecturas con filtros opcionales: por vehículo y por rango de la fecha de lectura.
     *
     * @return list<OdometerReading>
     */
    public function listBy(
        ?string $vehicleId = null,
        ?DateTimeImmutable $from = null,
        ?DateTimeImmutable $to = null,
    ): array;
}

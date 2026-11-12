<?php

declare(strict_types=1);

namespace Logistics\Execution\Infrastructure\ReadModel;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Connection;
use Logistics\Execution\Application\Contract\VehicleFitnessReader;

/**
 * Lee la aptitud del vehículo desde `fleet_vehicles.unfit_since` (fecha, nullable) por el query builder,
 * en solo lectura y sin acoplar Ejecución al dominio de Flota.
 *
 * Defensivo: si la columna no existe (Flota todavía sin la migración del checklist), no hay chequeo. No
 * apto = `unfit_since` no nulo y `unfit_since <= hoy` en America/La_Paz (la fecha operativa del negocio).
 */
final class DbVehicleFitnessReader implements VehicleFitnessReader
{
    private const string TIMEZONE = 'America/La_Paz';

    /** Memo de la existencia de la columna (una consulta al catálogo por instancia). */
    private ?bool $hasColumn = null;

    public function __construct(
        private readonly Connection $connection,
    ) {}

    public function isUnfit(string $vehicleId): bool
    {
        if (trim($vehicleId) === '' || !$this->columnExists()) {
            return false;
        }

        $today = (new DateTimeImmutable('now', new DateTimeZone(self::TIMEZONE)))->format('Y-m-d');

        return $this->connection->table('fleet_vehicles')
            ->where('id', $vehicleId)
            ->whereNotNull('unfit_since')
            ->whereDate('unfit_since', '<=', $today)
            ->exists();
    }

    private function columnExists(): bool
    {
        return $this->hasColumn ??= $this->connection->getSchemaBuilder()->hasColumn('fleet_vehicles', 'unfit_since');
    }
}

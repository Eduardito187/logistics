<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Domain\Contract;

use DateTimeImmutable;

/**
 * Puerto de lectura de la aptitud para despachar de un vehículo, que decide el contexto Flota (checklist
 * pre-salida con falla crítica → NO APTO hasta que un supervisor lo habilite).
 *
 * fleetops no depende del package fleet: la implementación (Infrastructure) lee el dato por valor. Así el
 * contexto sigue siendo extraíble a un servicio aparte (la lectura pasaría a ser un ReadModel replicado
 * por los eventos fleet.vehicle_marked_unfit / fleet.vehicle_cleared).
 */
interface VehicleFitnessGate
{
    /**
     * Motivo legible si el vehículo NO está apto para despachar en esa fecha; null si está apto (o si el
     * vehículo no está registrado en flota).
     */
    public function unfitReason(string $vehicleId, DateTimeImmutable $on): ?string;
}

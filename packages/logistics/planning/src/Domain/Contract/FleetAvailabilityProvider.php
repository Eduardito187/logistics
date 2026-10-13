<?php

declare(strict_types=1);

namespace Logistics\Planning\Domain\Contract;

use Logistics\Planning\Domain\ValueObject\PlanDate;
use Logistics\Planning\Domain\ValueObject\VehicleCapacitySpec;

/**
 * Puerto de solo lectura que entrega los camiones DISPONIBLES para un día, con su capacidad (kg/m3),
 * tripulación y el departamento que cubren (derivado de su nodo base). El dominio define QUÉ necesita;
 * la implementación (que lee el contexto de Flota, Red y Cobertura) vive en Infrastructure.
 */
interface FleetAvailabilityProvider
{
    /**
     * Camiones disponibles para la fecha dada.
     *
     * @return list<VehicleCapacitySpec>
     */
    public function availableFor(PlanDate $date): array;
}

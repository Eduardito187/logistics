<?php

declare(strict_types=1);

namespace Logistics\Planning\Domain\ValueObject;

use Logistics\Planning\Domain\Exception\PlanningException;

/**
 * Camión disponible visto por el motor de empaque: su capacidad (kg/m3), su nodo base, si tiene
 * tripulación completa y, si se pudo resolver, el departamento que cubre (derivado de su nodo base).
 *
 * Un camión con departmentId null es flexible: puede cubrir cualquier departamento, pero en cuanto el
 * empaque le asigna el primer pedido queda LIGADO a ese departamento (nunca mezcla departamentos).
 *
 * Dato puro, inmutable. Lo aporta quien conoce la flota; planificación no consulta Fleet desde el
 * dominio.
 */
final readonly class VehicleCapacitySpec
{
    private function __construct(
        public string $vehicleId,
        public float $capacityKg,
        public float $capacityM3,
        public string $homeBaseNodeId,
        public bool $hasCrew,
        public ?string $departmentId,
        public ?float $homeBaseLatitude,
        public ?float $homeBaseLongitude,
        public VehicleRouteProfile $profile,
    ) {}

    public static function of(
        string $vehicleId,
        float $capacityKg,
        float $capacityM3,
        string $homeBaseNodeId,
        bool $hasCrew,
        ?string $departmentId = null,
        ?float $homeBaseLatitude = null,
        ?float $homeBaseLongitude = null,
        ?VehicleRouteProfile $profile = null,
    ): self {
        $trimmedVehicle = trim($vehicleId);
        if ($trimmedVehicle === '') {
            throw PlanningException::because('el camión disponible necesita un id');
        }
        if ($capacityKg <= 0.0) {
            throw PlanningException::because("capacidad en kg inválida: {$capacityKg}");
        }
        if ($capacityM3 <= 0.0) {
            throw PlanningException::because("capacidad en m3 inválida: {$capacityM3}");
        }

        return new self(
            $trimmedVehicle,
            $capacityKg,
            $capacityM3,
            trim($homeBaseNodeId),
            $hasCrew,
            $departmentId,
            $homeBaseLatitude,
            $homeBaseLongitude,
            // Sin perfil explícito, el camión no impone restricciones de ruteo (comportamiento previo).
            $profile ?? VehicleRouteProfile::unrestricted(),
        );
    }

    /**
     * Coordenadas del nodo base del camión como punto, o null si no se resolvieron. Sirven de DEPÓSITO
     * aproximado del grupo cuando no se pasa un origen (CD) explícito al backend.
     */
    public function homeBasePoint(): ?GeoPoint
    {
        if ($this->homeBaseLatitude === null || $this->homeBaseLongitude === null) {
            return null;
        }

        return GeoPoint::of($this->homeBaseLatitude, $this->homeBaseLongitude);
    }
}

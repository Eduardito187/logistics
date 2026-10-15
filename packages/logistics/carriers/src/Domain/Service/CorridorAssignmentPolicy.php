<?php

declare(strict_types=1);

namespace Logistics\Carriers\Domain\Service;

use Logistics\Carriers\Domain\Exception\CarriersException;
use Logistics\Carriers\Domain\ValueObject\VehicleClass;

/**
 * Política de asignación de un corredor a una clase de vehículo según el volumen a mover.
 *
 * Es la regla de consolidación entre departamentos (P2-CAR-03): un corredor de alto volumen viaja por
 * tráiler, el de volumen medio por camión y el puntual o de bajo volumen se subcontrata a un tercero.
 * Vive en el dominio, es PHP puro y no decide el transportista concreto (eso lo hace la consulta de
 * tarifas más baratas para la clase elegida); solo traduce un volumen en la clase apropiada.
 *
 * Los umbrales son el contrato de negocio por defecto. Si en el futuro se vuelven configurables
 * (settings versionados), esta clase recibiría los umbrales por constructor sin cambiar su interfaz.
 */
final readonly class CorridorAssignmentPolicy
{
    /** Volumen mínimo en m³ para justificar un tráiler completo. */
    private const TRAILER_MIN_M3 = 40.0;

    /** Volumen mínimo en m³ para justificar un camión. Por debajo se subcontrata a un tercero. */
    private const TRUCK_MIN_M3 = 12.0;

    /**
     * Elige la clase de vehículo apropiada para el volumen dado (en m³).
     */
    public function classForVolume(float $volumeM3): VehicleClass
    {
        if ($volumeM3 <= 0.0) {
            throw CarriersException::because("El volumen debe ser mayor que cero: {$volumeM3}.");
        }

        return match (true) {
            $volumeM3 >= self::TRAILER_MIN_M3 => VehicleClass::Trailer,
            $volumeM3 >= self::TRUCK_MIN_M3 => VehicleClass::Truck,
            default => VehicleClass::ThirdParty,
        };
    }
}

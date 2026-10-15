<?php

declare(strict_types=1);

namespace Logistics\Planning\Domain\Service;

use Logistics\Planning\Domain\ValueObject\ShipmentRouteRequirement;
use Logistics\Planning\Domain\ValueObject\VehicleRouteProfile;

/**
 * Regla PURA de FACTIBILIDAD de ruteo (R-04): dado el perfil de un camión y los requisitos de un envío,
 * decide si ese camión PUEDE llevar ese envío. PHP puro, determinista, sin I/O.
 *
 * La usan el solver/empaque para DESCARTAR asignaciones infactibles: un envío que excede la altura o el
 * peso por bulto del camión, o que va a una zona que el camión no puede cubrir, o cuyo tipo de carga el
 * camión no admite, NO entra a ese camión. La capacidad total (kg/m3 acumulados de la ruta) la sigue
 * manejando el bin-packing / Clarke-Wright; esta clase solo decide los límites DUROS por parada.
 *
 * COMPATIBILIDAD: si el perfil no tiene restricciones y el requisito está vacío (caso por defecto), todo
 * es factible y el comportamiento previo del solver no cambia.
 */
final readonly class VehicleCompatibility
{
    /**
     * ¿El camión con este perfil puede servir un envío con estos requisitos? Todas las restricciones son
     * conjuntivas (deben cumplirse todas). Un atributo no definido en el perfil o en el requisito no
     * restringe.
     */
    public function canServe(VehicleRouteProfile $profile, ShipmentRouteRequirement $requirement): bool
    {
        // Atajo barato para el caso por defecto (sin restricciones ni requisitos): siempre factible.
        if ($profile->isUnrestricted() || $requirement->isEmpty()) {
            return true;
        }

        return $this->loadTypeFits($profile, $requirement)
            && $this->zoneFits($profile, $requirement)
            && $this->heightFits($profile, $requirement)
            && $this->weightFits($profile, $requirement);
    }

    /**
     * El tipo de carga del envío debe estar en la lista blanca del camión (si el camión define una). Un
     * camión sin lista admite cualquier tipo; un envío sin tipo no restringe.
     */
    private function loadTypeFits(VehicleRouteProfile $profile, ShipmentRouteRequirement $requirement): bool
    {
        if ($profile->allowedLoadTypes === null || $requirement->loadType === null) {
            return true;
        }

        return in_array($requirement->loadType, $profile->allowedLoadTypes, true);
    }

    /**
     * La zona de destino no puede estar prohibida y, si el camión define lista blanca de zonas, debe estar
     * en ella. La lista negra gana sobre la blanca. Un envío sin zona no restringe.
     */
    private function zoneFits(VehicleRouteProfile $profile, ShipmentRouteRequirement $requirement): bool
    {
        $zone = $requirement->zone;
        if ($zone === null) {
            return true;
        }

        if (in_array($zone, $profile->forbiddenZones, true)) {
            return false;
        }

        if ($profile->allowedZones === null) {
            return true;
        }

        return in_array($zone, $profile->allowedZones, true);
    }

    /**
     * La altura del bulto debe caber en la altura máxima del camión (si la define). Sin límite o sin
     * altura declarada, no restringe.
     */
    private function heightFits(VehicleRouteProfile $profile, ShipmentRouteRequirement $requirement): bool
    {
        if ($profile->maxHeightM === null || $requirement->heightM === null) {
            return true;
        }

        return $requirement->heightM <= $profile->maxHeightM + 1e-9;
    }

    /**
     * El peso del bulto individual debe caber en el peso máximo por bulto del camión (si lo define). Sin
     * límite o sin peso declarado, no restringe.
     */
    private function weightFits(VehicleRouteProfile $profile, ShipmentRouteRequirement $requirement): bool
    {
        if ($profile->maxWeightKg === null || $requirement->weightKg === null) {
            return true;
        }

        return $requirement->weightKg <= $profile->maxWeightKg + 1e-9;
    }
}

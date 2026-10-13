<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Domain\ValueObject;

use Logistics\FleetOps\Domain\Exception\FleetOpsException;

/**
 * Lectura de odómetro en kilómetros enteros. El odómetro no retrocede: lo usan combustible (consumo
 * entre cargas), mantenimiento (planes por km) y salidas (km recorridos). En telemetría el odómetro
 * viene en metros (`telemetry_pings.odometer_m`); aquí la unidad humana es el kilómetro.
 */
final readonly class Odometer
{
    private function __construct(public int $km) {}

    public static function km(int $km): self
    {
        if ($km < 0) {
            throw FleetOpsException::because("El odómetro no puede ser negativo: {$km}.");
        }

        return new self($km);
    }

    /**
     * Kilómetros recorridos desde una lectura anterior. Falla si la lectura previa es mayor (el odómetro
     * no retrocede); los contextos que lo permitan (cambio de tablero) deben manejarlo aparte.
     */
    public function kmSince(self $earlier): int
    {
        if ($earlier->km > $this->km) {
            throw FleetOpsException::because('El odómetro no puede retroceder.');
        }

        return $this->km - $earlier->km;
    }
}

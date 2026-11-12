<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Contract;

/**
 * Puerta de lectura de la APTITUD del vehículo para salir a reparto (contexto Flota).
 *
 * Un vehículo queda NO APTO cuando falló un ítem crítico del checklist pre-viaje y nadie lo liberó
 * (`fleet_vehicles.unfit_since` no nulo y ya vigente en la zona horaria operativa). Ejecución no se acopla
 * al dominio de Flota: solo pregunta por id, en primitivos.
 */
interface VehicleFitnessReader
{
    /** Motivo canónico (inglés, se muestra tal cual en el problem detail) de un vehículo no apto. */
    public const string UNFIT_DETAIL = 'Vehicle not fit to dispatch (failed critical pre-trip item, not cleared)';

    /**
     * ¿El vehículo está marcado como no apto para despachar hoy? Si la columna no existe todavía (Flota
     * sin migrar), o el vehículo no existe, responde false: la existencia la valida quien llama.
     */
    public function isUnfit(string $vehicleId): bool;
}

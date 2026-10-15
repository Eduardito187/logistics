<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Domain\Service;

use Logistics\Telemetry\Domain\Model\Trip;
use Logistics\Telemetry\Domain\Model\VehiclePing;

/**
 * Servicio de dominio puro que decide, para cada ping entrante, si abre, continúa o cierra+abre un trip.
 *
 * La regla es el hueco temporal: si entre el último punto del trip activo y el ping nuevo pasó más del
 * `gapMinutes` configurado, el trip se da por terminado y empieza otro. Sin estado, sin reloj, sin
 * config: todo entra por parámetro, de modo que es trivialmente testeable y determinista.
 */
final readonly class TripTracker
{
    /**
     * @param Trip|null $active trip actualmente abierto del vehículo (si lo hay)
     */
    public function decide(?Trip $active, VehiclePing $ping, int $gapMinutes, float $movingThresholdKph): TripAction
    {
        if ($active === null || !$active->isActive()) {
            return TripAction::StartNew;
        }

        // El umbral de movimiento no cambia la decisión de apertura/cierre (que depende solo del hueco
        // temporal); se mantiene en la firma porque es el parámetro de negocio que alimenta a
        // Trip::addPoint aguas abajo, de modo que el orquestador lo propague desde un único origen.
        $gapSeconds = $ping->recordedAt()->getTimestamp() - $active->lastPointAt()->getTimestamp();
        if ($gapSeconds > $gapMinutes * 60) {
            return TripAction::CloseAndStartNew;
        }

        return TripAction::Continue;
    }
}

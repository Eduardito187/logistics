<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Domain\Service;

use DateTimeImmutable;
use DateTimeZone;
use Logistics\Telemetry\Domain\Model\Geofence;
use Logistics\Telemetry\Domain\ValueObject\AlertSeverity;
use Logistics\Telemetry\Domain\ValueObject\AlertType;
use Logistics\Telemetry\Domain\ValueObject\GeofenceType;
use Logistics\Telemetry\Domain\ValueObject\GeoPoint;
use Logistics\Telemetry\Domain\ValueObject\Speed;

/**
 * Detecta mal uso a partir de una lectura de telemetría. Servicio de dominio PURO: recibe TODO por
 * parámetro (el ping, las geocercas activas y los umbrales) y no conoce Clock, config ni Eloquent. Así
 * se puede probar con vectores fijos y el orquestador de ingesta (Grupo A) decide de dónde salen los
 * umbrales (config) y el reloj.
 *
 * Devuelve la lista de alertas a levantar como primitivos {type, severity, detail}; quién las
 * materializa como agregado TelemetryAlert es el handler de ingesta, no este servicio.
 *
 * Reglas evaluadas sobre un ping:
 *  - exceso de velocidad (speed > límite);
 *  - movimiento fuera del horario laboral (en la zona horaria dada);
 *  - incursión en una geocerca de tipo ForbiddenZone;
 *  - inactividad prolongada con motor encendido (usa el ocioso acumulado que trae el orquestador);
 *  - uso sin ruta asignada activa (vehículo en movimiento sin ruta).
 */
final readonly class MisuseAnalyzer
{
    /**
     * @param  list<Geofence>                                                            $activeGeofences geocercas activas a evaluar
     * @param  string                                                                    $workStart       inicio del horario laboral 'HH:MM'
     * @param  string                                                                    $workEnd         fin del horario laboral 'HH:MM'
     * @param  string                                                                    $timezone        zona horaria del horario laboral (p. ej. 'America/La_Paz')
     * @return list<array{type: string, severity: string, detail: array<string, mixed>}>
     */
    public function analyze(
        GeoPoint $point,
        ?Speed $speed,
        DateTimeImmutable $recordedAt,
        ?bool $ignition,
        bool $hasActiveRoute,
        array $activeGeofences,
        string $workStart,
        string $workEnd,
        string $timezone,
        float $speedingLimitKph,
        float $movingThresholdKph,
        int $idleAlertMinutes,
        int $accumulatedIdleSeconds,
    ): array {
        $alerts = [];
        $moving = $speed !== null && $speed->isMoving($movingThresholdKph);

        // Exceso de velocidad.
        if ($speed !== null && $speed->exceeds($speedingLimitKph)) {
            $alerts[] = [
                'type' => AlertType::Speeding->value,
                'severity' => AlertSeverity::Warning->value,
                'detail' => [
                    'speed_kph' => $speed->kph,
                    'limit_kph' => $speedingLimitKph,
                ],
            ];
        }

        // Movimiento fuera del horario laboral.
        if ($moving && $this->isOutsideWorkingHours($recordedAt, $workStart, $workEnd, $timezone)) {
            $alerts[] = [
                'type' => AlertType::AfterHoursMovement->value,
                'severity' => AlertSeverity::Warning->value,
                'detail' => [
                    'work_start' => $workStart,
                    'work_end' => $workEnd,
                    'timezone' => $timezone,
                ],
            ];
        }

        // Incursión en zona prohibida.
        foreach ($activeGeofences as $geofence) {
            if ($geofence->type() === GeofenceType::ForbiddenZone && $geofence->contains($point)) {
                $alerts[] = [
                    'type' => AlertType::GeofenceViolation->value,
                    'severity' => AlertSeverity::Critical->value,
                    'detail' => [
                        'geofence_id' => $geofence->id()->toString(),
                        'geofence_name' => $geofence->name(),
                        'geofence_type' => $geofence->type()->value,
                    ],
                ];
            }
        }

        // Inactividad prolongada con motor encendido (ocioso acumulado que aporta el orquestador).
        if ($ignition === true && !$moving && $accumulatedIdleSeconds >= $idleAlertMinutes * 60) {
            $alerts[] = [
                'type' => AlertType::ExcessiveIdle->value,
                'severity' => AlertSeverity::Warning->value,
                'detail' => [
                    'idle_seconds' => $accumulatedIdleSeconds,
                    'threshold_minutes' => $idleAlertMinutes,
                ],
            ];
        }

        // Uso sin ruta asignada activa: el vehículo circula sin ruta.
        if ($moving && !$hasActiveRoute) {
            $alerts[] = [
                'type' => AlertType::UnauthorizedUse->value,
                'severity' => AlertSeverity::Warning->value,
                'detail' => [
                    'speed_kph' => $speed?->kph,
                ],
            ];
        }

        return $alerts;
    }

    /**
     * ¿El instante cae fuera de la franja laboral, leído en la zona horaria dada? Compara 'HH:MM'
     * lexicográficamente, que para hora de 24h coincide con el orden cronológico.
     */
    private function isOutsideWorkingHours(
        DateTimeImmutable $recordedAt,
        string $workStart,
        string $workEnd,
        string $timezone,
    ): bool {
        $local = $recordedAt->setTimezone(new DateTimeZone($timezone));
        $time = $local->format('H:i');

        return $time < $workStart || $time > $workEnd;
    }
}

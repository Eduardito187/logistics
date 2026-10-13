<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Application\Command\RecordVehiclePings;

use DateTimeImmutable;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;
use Logistics\Shared\Domain\Clock\Clock;
use Logistics\Telemetry\Domain\Contract\AlertRepository;
use Logistics\Telemetry\Domain\Contract\GeofenceRepository;
use Logistics\Telemetry\Domain\Contract\PingRepository;
use Logistics\Telemetry\Domain\Contract\TripRepository;
use Logistics\Telemetry\Domain\Contract\VehiclePositionRepository;
use Logistics\Telemetry\Domain\Exception\TelemetryException;
use Logistics\Telemetry\Domain\Model\TelemetryAlert;
use Logistics\Telemetry\Domain\Model\Trip;
use Logistics\Telemetry\Domain\Model\VehiclePing;
use Logistics\Telemetry\Domain\Service\MisuseAnalyzer;
use Logistics\Telemetry\Domain\Service\TripAction;
use Logistics\Telemetry\Domain\Service\TripTracker;
use Logistics\Telemetry\Domain\ValueObject\AlertSeverity;
use Logistics\Telemetry\Domain\ValueObject\AlertType;
use Logistics\Telemetry\Domain\ValueObject\GeoPoint;
use Logistics\Telemetry\Domain\ValueObject\Heading;
use Logistics\Telemetry\Domain\ValueObject\PingId;
use Logistics\Telemetry\Domain\ValueObject\PingSource;
use Logistics\Telemetry\Domain\ValueObject\Speed;

/**
 * Ingiere un lote de lecturas GPS: persiste los pings (append-only, en bloque), mantiene el trip activo
 * del vehículo vía TripTracker y actualiza su posición en vivo.
 *
 * Alto volumen: NO se emite un evento por ping. Solo se mandan al outbox los hechos de negocio del trip
 * (TripStarted / TripCompleted), bajo el tipo de agregado 'telemetry.trip'. El save de los repositorios y
 * el append al outbox corren dentro de la misma transacción que abre el decorador del CommandBus.
 *
 * Los umbrales (lote máximo, hueco de trip, umbral de movimiento) se leen de `logistics-telemetry` para
 * respetar la configuración por .env sin acoplar el handler a escalares inyectados.
 *
 * @implements CommandHandler<RecordVehiclePings>
 */
final readonly class RecordVehiclePingsHandler implements CommandHandler
{
    public function __construct(
        private PingRepository $pings,
        private VehiclePositionRepository $positions,
        private TripRepository $trips,
        private TripTracker $tracker,
        private Clock $clock,
        private RecordDomainEvents $recordEvents,
        private GeofenceRepository $geofences,
        private AlertRepository $alerts,
        private MisuseAnalyzer $misuse,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof RecordVehiclePings);

        $maxBatch = (int) config('logistics-telemetry.ingest.max_batch', 500);
        if (count($command->pings) > $maxBatch) {
            throw TelemetryException::because(
                'El lote de pings excede el máximo permitido ('.count($command->pings)." > {$maxBatch}).",
            );
        }
        if ($command->pings === []) {
            return;
        }

        $gapMinutes = (int) config('logistics-telemetry.trip.gap_minutes', 10);
        $movingThreshold = (float) config('logistics-telemetry.movement.moving_threshold_kph', 3.0);

        $source = PingSource::from($command->source);
        $receivedAt = $this->clock->now();

        $ordered = $this->sortByRecordedAt($command->pings);

        $active = $this->trips->activeForVehicle($command->vehicleId);
        /** @var list<VehiclePing> $toAppend */
        $toAppend = [];
        $lastPing = null;
        $lastMoving = false;

        foreach ($ordered as $row) {
            $recordedAt = new DateTimeImmutable($row['recorded_at']);
            $point = GeoPoint::of($row['lat'], $row['lng']);
            $speed = $this->speedOf($row);

            $ping = VehiclePing::record(
                PingId::generate(),
                $command->vehicleId,
                $command->driverId,
                $row['device_id'] ?? null,
                $command->routeId,
                $point,
                $speed,
                $this->headingOf($row),
                $this->floatOrNull($row['accuracy_m'] ?? null),
                isset($row['ignition']) ? (bool) $row['ignition'] : null,
                $this->intOrNull($row['odometer_m'] ?? null),
                $recordedAt,
                $receivedAt,
                $source,
            );

            $action = $this->tracker->decide($active, $ping, $gapMinutes, $movingThreshold);

            if ($action === TripAction::CloseAndStartNew && $active !== null) {
                $active->complete($active->lastPointAt());
                $this->trips->save($active);
                $this->recordEvents->record($active->releaseEvents(), 'telemetry.trip');
                $active = null;
            }

            if ($action === TripAction::StartNew || $action === TripAction::CloseAndStartNew) {
                $active = Trip::start(
                    $this->trips->nextIdentity(),
                    $command->vehicleId,
                    $command->driverId,
                    $command->routeId,
                    $point,
                    $recordedAt,
                );
            }

            // En este punto siempre hay un trip activo (se abrió arriba o venía abierto).
            assert($active instanceof Trip);
            $active->addPoint($point, $speed, $recordedAt, $movingThreshold);

            $ping = $ping->withTripId($active->id());
            $toAppend[] = $ping;
            $lastPing = $ping;
            $lastMoving = $speed !== null && $speed->isMoving($movingThreshold);
        }

        $this->pings->appendMany($toAppend);

        if ($active !== null) {
            $this->trips->save($active);
            $this->recordEvents->record($active->releaseEvents(), 'telemetry.trip');
        }

        if ($lastPing !== null) {
            $this->positions->upsertFromPing($lastPing, $lastMoving);
            $this->detectMisuse($lastPing, $active, $command->routeId !== null);
        }
    }

    /**
     * Evalúa la última lectura contra las geocercas activas y las reglas de mal uso (exceso de
     * velocidad, movimiento fuera de horario, zona prohibida, inactividad prolongada, uso sin ruta) y
     * levanta alertas al dominio (→ outbox 'telemetry.alert' → notificación al supervisor).
     *
     * Se evalúa SOLO la última lectura del lote (la posición vigente), no cada ping, para no inundar.
     * Debounce: mientras exista una alerta del mismo tipo sin reconocer para ese vehículo, no se levanta
     * otra — así una condición continua (p. ej. exceso sostenido) genera una alerta, no un torrente.
     */
    private function detectMisuse(VehiclePing $ping, ?Trip $trip, bool $hasActiveRoute): void
    {
        $detected = $this->misuse->analyze(
            $ping->point(),
            $ping->speed(),
            $ping->recordedAt(),
            $ping->ignition(),
            $hasActiveRoute,
            $this->geofences->activeAll(),
            (string) config('logistics-telemetry.working_hours.start', '06:00'),
            (string) config('logistics-telemetry.working_hours.end', '20:00'),
            (string) config('logistics-telemetry.working_hours.timezone', 'America/La_Paz'),
            (float) config('logistics-telemetry.speeding.limit_kph', 90.0),
            (float) config('logistics-telemetry.movement.moving_threshold_kph', 3.0),
            (int) config('logistics-telemetry.idle.alert_minutes', 15),
            $trip?->idleS() ?? 0,
        );

        foreach ($detected as $signal) {
            $type = AlertType::from($signal['type']);

            // Debounce: ya hay una alerta abierta (sin reconocer) de este tipo para el vehículo.
            if ($this->alerts->listBy($ping->vehicleId(), $type->value, false) !== []) {
                continue;
            }

            $alert = TelemetryAlert::raise(
                $this->alerts->nextIdentity(),
                $ping->vehicleId(),
                null,
                $type,
                AlertSeverity::from($signal['severity']),
                $ping->point(),
                $signal['detail'],
                $ping->recordedAt(),
            );
            $this->alerts->save($alert);
            $this->recordEvents->record($alert->releaseEvents(), 'telemetry.alert');
        }
    }

    /**
     * Ordena las lecturas por `recorded_at` ascendente para acumular el trip en el orden real del tiempo.
     *
     * @param  list<array{lat: float, lng: float, recorded_at: string, speed_kph?: float|null, heading?: int|null, accuracy_m?: float|null, ignition?: bool|null, odometer_m?: int|null, device_id?: string|null}> $pings
     * @return list<array{lat: float, lng: float, recorded_at: string, speed_kph?: float|null, heading?: int|null, accuracy_m?: float|null, ignition?: bool|null, odometer_m?: int|null, device_id?: string|null}>
     */
    private function sortByRecordedAt(array $pings): array
    {
        usort(
            $pings,
            static fn (array $a, array $b): int => (new DateTimeImmutable($a['recorded_at']))->getTimestamp()
                <=> (new DateTimeImmutable($b['recorded_at']))->getTimestamp(),
        );

        return $pings;
    }

    /**
     * @param array{speed_kph?: float|null} $row
     */
    private function speedOf(array $row): ?Speed
    {
        $value = $row['speed_kph'] ?? null;

        return $value === null ? null : Speed::kmh((float) $value);
    }

    /**
     * @param array{heading?: int|null} $row
     */
    private function headingOf(array $row): ?Heading
    {
        $value = $row['heading'] ?? null;

        return $value === null ? null : Heading::degrees((int) $value);
    }

    private function floatOrNull(float | int | string | null $value): ?float
    {
        return $value === null ? null : (float) $value;
    }

    private function intOrNull(float | int | string | null $value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}

<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Application\Command\RecordVehiclePings;

use DateTimeImmutable;
use DateTimeZone;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;
use Logistics\Shared\Domain\Clock\Clock;
use Logistics\Telemetry\Application\Contract\ActiveRouteReader;
use Logistics\Telemetry\Application\Contract\ShipmentCustodyReader;
use Logistics\Telemetry\Domain\Contract\AlertRepository;
use Logistics\Telemetry\Domain\Contract\GeofenceRepository;
use Logistics\Telemetry\Domain\Contract\LivePositionBroadcaster;
use Logistics\Telemetry\Domain\Contract\PingRepository;
use Logistics\Telemetry\Domain\Contract\TripRepository;
use Logistics\Telemetry\Domain\Contract\VehiclePositionRepository;
use Logistics\Telemetry\Domain\Event\VehiclePositionReported;
use Logistics\Telemetry\Domain\Exception\TelemetryException;
use Logistics\Telemetry\Domain\Model\TelemetryAlert;
use Logistics\Telemetry\Domain\Model\Trip;
use Logistics\Telemetry\Domain\Model\VehiclePing;
use Logistics\Telemetry\Domain\Service\MisuseAnalyzer;
use Logistics\Telemetry\Domain\Service\RouteDeviationMeter;
use Logistics\Telemetry\Domain\Service\SustainedCondition;
use Logistics\Telemetry\Domain\Service\TripAction;
use Logistics\Telemetry\Domain\Service\TripTracker;
use Logistics\Telemetry\Domain\ValueObject\AlertSeverity;
use Logistics\Telemetry\Domain\ValueObject\AlertType;
use Logistics\Telemetry\Domain\ValueObject\FixKey;
use Logistics\Telemetry\Domain\ValueObject\GeoPoint;
use Logistics\Telemetry\Domain\ValueObject\Heading;
use Logistics\Telemetry\Domain\ValueObject\PingId;
use Logistics\Telemetry\Domain\ValueObject\PingSource;
use Logistics\Telemetry\Domain\ValueObject\Speed;

/**
 * Ingiere un lote de lecturas GPS: persiste los pings (append-only, en bloque), mantiene el trip activo
 * del vehículo vía TripTracker, actualiza su posición en vivo, evalúa alertas y avisa al seguimiento por envío.
 *
 * Es el camino RECOMENDADO para la app (un POST por lote, no por lectura) y alimenta CADA cosa que alimenta la
 * ingesta: pings (migas de pan por envío), trips, posición en vivo + fan-out, geocercas/alertas de mal uso,
 * desvío de ruta y exceso sostenido, y el seguimiento por envío vía `telemetry.vehicle_position_reported`.
 *
 * Robustez del lote:
 *  - Idempotente por lectura: cada lectura tiene su FixKey (`fix_id` del cliente o `device_id@recorded_at`).
 *    Las repetidas dentro del lote y las ya persistidas se descartan antes de tocar trips (un reintento no
 *    infla la distancia) y el insert es ON CONFLICT DO NOTHING (carrera entre dos reintentos concurrentes).
 *  - Tolerante al desorden: se ordena por `recorded_at`; una lectura ATRASADA (anterior a la marca de agua
 *    = último punto del trip activo / última posición en vivo) se persiste para la traza pero no se suma al
 *    trip, no retrocede la posición en vivo y no evalúa alertas.
 *
 * Alto volumen: NO se emite un evento por ping. Al outbox van los hechos del trip ('telemetry.trip'), las
 * alertas ('telemetry.alert') y, como mucho, UN aviso de posición por lote ('telemetry.vehicle') si hay
 * envíos a bordo. El proceso entero corre dentro de la transacción que abre el decorador del CommandBus.
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
        private LivePositionBroadcaster $liveBroadcaster,
        private ShipmentCustodyReader $custody,
        private ActiveRouteReader $routes,
        private SustainedCondition $sustained = new SustainedCondition,
        private RouteDeviationMeter $deviationMeter = new RouteDeviationMeter,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof RecordVehiclePings);

        $this->assertBatchSize($command);
        if ($command->pings === []) {
            return;
        }

        $gapMinutes = (int) config('logistics-telemetry.trip.gap_minutes', 10);
        $movingThreshold = (float) config('logistics-telemetry.movement.moving_threshold_kph', 3.0);

        $fresh = $this->newPings($command, PingSource::from($command->source), $this->clock->now());
        if ($fresh === []) {
            // El lote entero ya estaba persistido (reintento): idempotente, no hay nada más que hacer.
            return;
        }

        $live = $this->positions->forVehicle($command->vehicleId);
        $active = $this->trips->activeForVehicle($command->vehicleId);
        $watermark = $this->watermark($active, $live);

        /** @var list<VehiclePing> $toAppend */
        $toAppend = [];
        /** @var list<VehiclePing> $inOrder */
        $inOrder = [];

        foreach ($fresh as $ping) {
            if ($watermark !== null && $ping->recordedAt() < $watermark) {
                // Lectura atrasada: se guarda para la traza (asociada al trip activo si cae en su ventana),
                // sin sumarla al trip ni mover la posición en vivo.
                $toAppend[] = $this->latePing($ping, $active);

                continue;
            }

            $active = $this->advanceTrip($active, $ping, $command, $gapMinutes, $movingThreshold);

            $ping = $ping->withTripId($active->id());
            $toAppend[] = $ping;
            $inOrder[] = $ping;
            $watermark = $ping->recordedAt();
        }

        $this->pings->appendMany($toAppend);

        if ($active !== null) {
            $this->trips->save($active);
            $this->recordEvents->record($active->releaseEvents(), 'telemetry.trip');
        }

        if ($inOrder === []) {
            // Solo llegaron lecturas atrasadas: la posición en vivo y las alertas no cambian.
            return;
        }

        $this->afterInOrderPings($command, $inOrder, $active, $movingThreshold);
    }

    /** Rechaza el lote si excede el máximo de lecturas configurado. */
    private function assertBatchSize(RecordVehiclePings $command): void
    {
        $maxBatch = (int) config('logistics-telemetry.ingest.max_batch', 500);
        if (count($command->pings) > $maxBatch) {
            throw TelemetryException::because(
                'El lote de pings excede el máximo permitido ('.count($command->pings)." > {$maxBatch}).",
            );
        }
    }

    /** Lectura atrasada: se asocia al trip activo solo si cae dentro de su ventana. */
    private function latePing(VehiclePing $ping, ?Trip $active): VehiclePing
    {
        return ($active !== null && $ping->recordedAt() >= $active->startedAt())
            ? $ping->withTripId($active->id())
            : $ping;
    }

    /**
     * Aplica la decisión del TripTracker para una lectura en orden: cierra el trip vigente si corresponde,
     * abre uno nuevo si hace falta y le suma el punto. Devuelve el trip activo resultante.
     */
    private function advanceTrip(
        ?Trip $active,
        VehiclePing $ping,
        RecordVehiclePings $command,
        int $gapMinutes,
        float $movingThreshold,
    ): Trip {
        $speed = $ping->speed();
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
                $ping->point(),
                $ping->recordedAt(),
            );
        }

        // En este punto siempre hay un trip activo (se abrió arriba o venía abierto).
        assert($active instanceof Trip);
        $active->addPoint($ping->point(), $speed, $ping->recordedAt(), $movingThreshold);

        return $active;
    }

    /**
     * Con la última lectura en orden: actualiza la posición en vivo, difunde el fan-out y evalúa alertas y
     * el aviso al seguimiento por envío.
     *
     * @param list<VehiclePing> $inOrder
     */
    private function afterInOrderPings(
        RecordVehiclePings $command,
        array $inOrder,
        ?Trip $active,
        float $movingThreshold,
    ): void {
        $lastPing = $inOrder[count($inOrder) - 1];
        $lastSpeed = $lastPing->speed();
        $lastMoving = $lastSpeed !== null && $lastSpeed->isMoving($movingThreshold);

        $this->positions->upsertFromPing($lastPing, $lastMoving);
        // Fan-out en vivo (aditivo): refresca el hot-state en Valkey y difunde la última posición a los
        // espectadores con throttle. No cambia la semántica de la ingesta ni hace I/O síncrono bloqueante.
        $this->liveBroadcaster->publish($lastPing, $lastMoving);

        $onboard = $this->custody->onboardShipmentIds($command->vehicleId);

        $this->detectMisuse($lastPing, $active, $command->routeId !== null);
        $this->detectSustained($command->vehicleId, $inOrder, $onboard !== []);
        $this->reportToShipmentTracking($lastPing, $onboard);
    }

    /**
     * Construye las lecturas del lote con su FixKey, descarta las repetidas dentro del lote y las ya
     * persistidas, y las devuelve ordenadas por `recorded_at` ascendente.
     *
     * @return list<VehiclePing>
     */
    private function newPings(RecordVehiclePings $command, PingSource $source, DateTimeImmutable $receivedAt): array
    {
        /** @var array<string, VehiclePing> $byKey */
        $byKey = [];
        $from = null;
        $to = null;

        foreach ($command->pings as $row) {
            $recordedAt = new DateTimeImmutable($row['recorded_at']);
            $deviceId = $row['device_id'] ?? null;
            $key = FixKey::of($row['fix_id'] ?? null, $deviceId, $recordedAt);
            if (isset($byKey[$key])) {
                continue;
            }

            $byKey[$key] = VehiclePing::record(
                PingId::generate(),
                $command->vehicleId,
                $command->driverId,
                $deviceId,
                $command->routeId,
                GeoPoint::of($row['lat'], $row['lng']),
                $this->speedOf($row),
                $this->headingOf($row),
                $this->floatOrNull($row['accuracy_m'] ?? null),
                isset($row['ignition']) ? (bool) $row['ignition'] : null,
                $this->intOrNull($row['odometer_m'] ?? null),
                $recordedAt,
                $receivedAt,
                $source,
                null,
                $key,
            );

            $from = ($from === null || $recordedAt < $from) ? $recordedAt : $from;
            $to = ($to === null || $recordedAt > $to) ? $recordedAt : $to;
        }

        if ($from !== null && $to !== null) {
            foreach ($this->pings->existingFixKeys($command->vehicleId, array_keys($byKey), $from, $to) as $known) {
                unset($byKey[$known]);
            }
        }

        $pings = array_values($byKey);
        usort(
            $pings,
            static fn (VehiclePing $a, VehiclePing $b): int => $a->recordedAt() <=> $b->recordedAt(),
        );

        return $pings;
    }

    /**
     * Marca de agua temporal del vehículo: lo más reciente entre el último punto del trip activo y la
     * última posición en vivo. Una lectura anterior es "atrasada".
     *
     * @param array{recorded_at: DateTimeImmutable}|null $live
     */
    private function watermark(?Trip $active, ?array $live): ?DateTimeImmutable
    {
        $tripMark = ($active !== null && $active->isActive()) ? $active->lastPointAt() : null;
        $liveMark = $live['recorded_at'] ?? null;

        if ($tripMark === null) {
            return $liveMark;
        }
        if ($liveMark === null) {
            return $tripMark;
        }

        return $tripMark > $liveMark ? $tripMark : $liveMark;
    }

    /**
     * Evalúa la última lectura contra las geocercas activas y las reglas de mal uso (movimiento fuera de
     * horario, zona prohibida, inactividad prolongada, uso sin ruta) y levanta alertas al dominio
     * (→ outbox 'telemetry.alert' → notificación al supervisor).
     *
     * El exceso de velocidad NO se toma de acá: se evalúa como condición sostenida (ver detectSustained).
     * Se evalúa SOLO la última lectura del lote (la posición vigente), no cada ping, para no inundar.
     * Debounce: mientras exista una alerta del mismo tipo sin reconocer para ese vehículo, no se levanta
     * otra — así una condición continua genera una alerta, no un torrente.
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
            $this->timezone(),
            (float) config('logistics-telemetry.speeding.limit_kph', 90.0),
            (float) config('logistics-telemetry.movement.moving_threshold_kph', 3.0),
            (int) config('logistics-telemetry.idle.alert_minutes', 15),
            $trip?->idleS() ?? 0,
        );

        foreach ($detected as $signal) {
            $type = AlertType::fromLoose($signal['type']);
            if ($type === AlertType::Speeding) {
                continue;
            }

            $this->raiseDebounced($ping, $type, AlertSeverity::from($signal['severity']), $signal['detail']);
        }
    }

    /**
     * Condiciones SOSTENIDAS evaluadas lectura a lectura sobre las lecturas en orden del lote, con el estado
     * persistido entre lotes (desde cuándo se sostiene cada una):
     *
     *  - exceso de velocidad: > `speeding.limit_kph` durante `speeding.sustained_seconds`;
     *  - desvío de ruta: a más de `deviation.max_distance_m` de la geometría del plan publicado del día
     *    durante `deviation.min_seconds`. Solo con envíos a bordo (sin carga, moverse fuera de la ruta —ir a
     *    cargar combustible, volver al nodo— no es un desvío de entrega) y solo si el plan tiene geometría.
     *
     * Cada condición levanta como mucho UNA alerta por lote (en la lectura donde cumple el mínimo) y además
     * respeta el debounce por alerta abierta.
     *
     * @param list<VehiclePing> $inOrder
     */
    private function detectSustained(string $vehicleId, array $inOrder, bool $hasCargo): void
    {
        $limitKph = (float) config('logistics-telemetry.speeding.limit_kph', 90.0);
        $speedSeconds = (int) config('logistics-telemetry.speeding.sustained_seconds', 20);
        $deviationEnabled = (bool) config('logistics-telemetry.deviation.enabled', true);
        $maxDistance = (float) config('logistics-telemetry.deviation.max_distance_m', 300.0);
        $deviationSeconds = (int) config('logistics-telemetry.deviation.min_seconds', 120);

        $state = $this->positions->watchState($vehicleId);
        $speedingSince = $state['speeding_since'];
        $offRouteSince = $state['off_route_since'];

        $last = $inOrder[count($inOrder) - 1];
        $geometry = ($deviationEnabled && $hasCargo)
            ? $this->routes->plannedGeometry($vehicleId, $this->localDay($last->recordedAt()))
            : [];

        $speedingAlert = null;
        $deviationAlert = null;

        foreach ($inOrder as $ping) {
            $speed = $ping->speed();
            $step = $this->sustained->step(
                $speedingSince,
                $speed !== null && $speed->exceeds($limitKph),
                $ping->recordedAt(),
                $speedSeconds,
            );
            $speedingSince = $step['since'];
            if ($step['fire'] && $speedingAlert === null) {
                $speedingAlert = [$ping, [
                    'speed_kph' => $speed?->kph,
                    'limit_kph' => $limitKph,
                    'sustained_seconds' => $step['sustained_seconds'],
                ]];
            }

            if ($geometry === []) {
                $offRouteSince = null;

                continue;
            }

            $distance = $this->deviationMeter->distanceToRoute($ping->point(), $geometry);
            $step = $this->sustained->step(
                $offRouteSince,
                $distance !== null && $distance > $maxDistance,
                $ping->recordedAt(),
                $deviationSeconds,
            );
            $offRouteSince = $step['since'];
            if ($step['fire'] && $deviationAlert === null) {
                $deviationAlert = [$ping, [
                    'distance_m' => $distance !== null ? round($distance, 1) : null,
                    'max_distance_m' => $maxDistance,
                    'sustained_seconds' => $step['sustained_seconds'],
                ]];
            }
        }

        // Solo se escribe si cambió (la mayoría de los lotes no excede ni se desvía: sin UPDATE extra).
        if ($speedingSince != $state['speeding_since'] || $offRouteSince != $state['off_route_since']) {
            $this->positions->saveWatchState($vehicleId, $speedingSince, $offRouteSince);
        }

        if ($speedingAlert !== null) {
            $this->raiseDebounced($speedingAlert[0], AlertType::Speeding, AlertSeverity::Warning, $speedingAlert[1]);
        }
        if ($deviationAlert !== null) {
            $this->raiseDebounced($deviationAlert[0], AlertType::RouteDeviation, AlertSeverity::Warning, $deviationAlert[1]);
        }
    }

    /**
     * Levanta la alerta salvo que ya haya una abierta (sin reconocer) del mismo tipo para el vehículo.
     *
     * @param array<string, mixed> $detail
     */
    private function raiseDebounced(VehiclePing $ping, AlertType $type, AlertSeverity $severity, array $detail): void
    {
        if ($this->alerts->countBy($ping->vehicleId(), $type->value, false) > 0) {
            return;
        }

        $alert = TelemetryAlert::raise(
            $this->alerts->nextIdentity(),
            $ping->vehicleId(),
            $ping->driverId(),
            $type,
            $severity,
            $ping->point(),
            $detail,
            $ping->recordedAt(),
        );
        $this->alerts->save($alert);
        $this->recordEvents->record($alert->releaseEvents(), 'telemetry.alert');
    }

    /**
     * Puente al seguimiento POR ENVÍO: un evento por lote con la última posición y los envíos a bordo. El
     * reactor del contexto tracking lo traduce a la posición del camión de cada envío (lo mismo que hacía el
     * POST por lectura a /app/tracking/shipments/{id}/position). Sin envíos a bordo no se emite nada.
     *
     * @param list<string> $onboard
     */
    private function reportToShipmentTracking(VehiclePing $ping, array $onboard): void
    {
        if ($onboard === []) {
            return;
        }

        $this->recordEvents->record([
            new VehiclePositionReported(
                $ping->vehicleId(),
                $ping->point()->lat,
                $ping->point()->lng,
                $ping->speed()?->kph,
                $ping->recordedAt()->format(DateTimeImmutable::ATOM),
                $onboard,
            ),
        ], 'telemetry.vehicle');
    }

    private function timezone(): string
    {
        return (string) config('logistics-telemetry.working_hours.timezone', 'America/La_Paz');
    }

    /** Día operativo (fecha local) de un instante, para buscar el plan/ruta del día. */
    private function localDay(DateTimeImmutable $at): DateTimeImmutable
    {
        return $at->setTimezone(new DateTimeZone($this->timezone()))->setTime(0, 0);
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

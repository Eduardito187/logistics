<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Infrastructure\Seeder;

use DateInterval;
use DateTimeImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Logistics\Telemetry\Domain\ValueObject\GeofenceType;
use Logistics\Telemetry\Domain\ValueObject\GeoPoint;
use Logistics\Telemetry\Domain\ValueObject\PingId;
use Logistics\Telemetry\Domain\ValueObject\PingSource;
use Logistics\Telemetry\Domain\ValueObject\TripId;
use Logistics\Telemetry\Domain\ValueObject\TripStatus;
use Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Factory\GeofenceFactory;
use Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Model\GeofenceRecord;
use Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Model\PingRecord;
use Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Model\TripRecord;
use Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Model\VehiclePositionRecord;

/**
 * Seeder propio del contexto Telemetría con datos de demo realistas.
 *
 * Siembra directamente la proyección de lectura (records Eloquent) vía fábricas y escrituras directas,
 * sin pasar por el CommandBus: es la vía estándar de demo y no depende del relay del outbox. El
 * integrador lo invoca desde el DatabaseSeeder raíz (no se registra aquí); también corre con
 * `php artisan db:seed --class=...`.
 *
 * Es IDEMPOTENTE (guardia skip-if-exists sobre la geocerca marcador) y SOLO ADITIVO: nunca borra ni
 * actualiza datos existentes (regla no-destructiva). Siembra tres geocercas con polígonos reales en
 * Bolivia y, para hasta dos vehículos existentes de `fleet_vehicles`, un recorrido de ~20 pings y su
 * trip cerrado con métricas coherentes.
 */
final class TelemetryDemoSeeder extends Seeder
{
    /** Geocerca marcador: su presencia indica que la demo ya fue sembrada. */
    private const MARKER_GEOFENCE = 'Demo DC El Alto';

    public function run(): void
    {
        // Idempotente: si el marcador ya está, no se vuelve a sembrar (no se regeneran UUIDs).
        if (GeofenceRecord::query()->where('name', self::MARKER_GEOFENCE)->exists()) {
            return;
        }

        $this->seedGeofences();
        $this->seedTrips();
    }

    /**
     * Tres geocercas de demo con polígonos reales en Bolivia: el CD de El Alto (zona autorizada), una
     * zona prohibida en el centro de La Paz y un área de descanso en Patacamaya (ruta La Paz–Oruro).
     */
    private function seedGeofences(): void
    {
        // Centro de distribución El Alto (zona autorizada de operación).
        GeofenceFactory::new()
            ->ofType(GeofenceType::AuthorizedZone)
            ->state([
                'name' => self::MARKER_GEOFENCE,
                'node_ref' => 'CD-LPZ',
                'polygon' => [
                    ['lat' => -16.5180, 'lng' => -68.1920],
                    ['lat' => -16.5180, 'lng' => -68.1780],
                    ['lat' => -16.5040, 'lng' => -68.1780],
                    ['lat' => -16.5040, 'lng' => -68.1920],
                ],
            ])
            ->create();

        // Zona prohibida: casco histórico de La Paz (restricción de camiones).
        GeofenceFactory::new()
            ->ofType(GeofenceType::ForbiddenZone)
            ->state([
                'name' => 'Demo restricted downtown La Paz',
                'node_ref' => null,
                'polygon' => [
                    ['lat' => -16.5020, 'lng' => -68.1380],
                    ['lat' => -16.5020, 'lng' => -68.1280],
                    ['lat' => -16.4920, 'lng' => -68.1280],
                    ['lat' => -16.4920, 'lng' => -68.1380],
                ],
            ])
            ->create();

        // Área de descanso autorizada en Patacamaya (ruta troncal La Paz–Oruro).
        GeofenceFactory::new()
            ->ofType(GeofenceType::RestArea)
            ->state([
                'name' => 'Demo rest area Patacamaya',
                'node_ref' => null,
                'polygon' => [
                    ['lat' => -17.2420, 'lng' => -67.9280],
                    ['lat' => -17.2420, 'lng' => -67.9180],
                    ['lat' => -17.2320, 'lng' => -67.9180],
                    ['lat' => -17.2320, 'lng' => -67.9280],
                ],
            ])
            ->create();
    }

    /**
     * Para hasta dos vehículos existentes de fleet, siembra un recorrido realista con su trip cerrado.
     * Si no hay vehículos sembrados aún, solo quedan las geocercas (se evita crear datos huérfanos).
     */
    private function seedTrips(): void
    {
        /** @var list<string> $vehicleIds */
        $vehicleIds = DB::table('fleet_vehicles')
            ->orderBy('id')
            ->limit(2)
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        foreach ($vehicleIds as $index => $vehicleId) {
            // Dos recorridos de demo que arrancan en días distintos para variedad.
            $startedAt = new DateTimeImmutable("2026-10-1{$index}T08:00:00-04:00");
            $this->seedTripForVehicle($vehicleId, $startedAt);
        }
    }

    /**
     * Construye ~20 pings de un descenso El Alto → La Paz centro (1 por minuto) y el trip que los agrupa,
     * con distancia haversine acumulada y métricas coherentes. Todos los ids son UUID v7 reales.
     */
    private function seedTripForVehicle(string $vehicleId, DateTimeImmutable $startedAt): void
    {
        $tripId = TripId::generate()->toString();
        $pointCount = 20;

        // Trayecto: desde el CD de El Alto hacia el centro de La Paz, bajando por la autopista.
        $startLat = -16.5100;
        $startLng = -68.1850;
        $stepLat = 0.0006;   // se acerca al centro
        $stepLng = 0.0028;

        $distanceM = 0.0;
        $maxSpeed = 0.0;
        $previous = null;
        $recordedAt = $startedAt;
        $lastLat = $startLat;
        $lastLng = $startLng;

        for ($i = 0; $i < $pointCount; $i++) {
            $lat = $startLat + ($stepLat * $i);
            $lng = $startLng + ($stepLng * $i);
            // Perfil de velocidad: arranque lento, crucero, frenado al final.
            $speed = match (true) {
                $i < 2 => 15.0 + $i * 5.0,
                $i >= $pointCount - 2 => 20.0,
                default => 40.0 + ($i % 3) * 7.0,
            };
            $maxSpeed = max($maxSpeed, $speed);

            $current = GeoPoint::of($lat, $lng);
            if ($previous !== null) {
                $distanceM += $previous->distanceMetersTo($current);
            }
            $previous = $current;
            $lastLat = $lat;
            $lastLng = $lng;

            PingRecord::query()->create([
                'id' => PingId::generate()->toString(),
                'vehicle_id' => $vehicleId,
                'driver_id' => null,
                'device_id' => 'demo-gps-'.substr($vehicleId, 0, 8),
                'route_id' => null,
                'lat' => $lat,
                'lng' => $lng,
                'speed_kph' => $speed,
                'heading' => 135,
                'accuracy_m' => 8.0,
                'ignition' => true,
                'odometer_m' => 120_000_000 + $i * 500,
                'recorded_at' => $recordedAt,
                'received_at' => $recordedAt,
                'source' => PingSource::App->value,
                'trip_id' => $tripId,
            ]);

            $recordedAt = $recordedAt->add(new DateInterval('PT60S'));
        }

        $durationS = ($pointCount - 1) * 60;

        TripRecord::query()->create([
            'id' => $tripId,
            'vehicle_id' => $vehicleId,
            'driver_id' => null,
            'route_id' => null,
            'status' => TripStatus::Completed->value,
            'started_at' => $startedAt,
            'ended_at' => $startedAt->add(new DateInterval('PT'.$durationS.'S')),
            'start_lat' => $startLat,
            'start_lng' => $startLng,
            'end_lat' => $lastLat,
            'end_lng' => $lastLng,
            'distance_m' => round($distanceM, 2),
            'duration_s' => $durationS,
            'moving_s' => $durationS, // todo el recorrido fue en movimiento
            'idle_s' => 0,
            'max_speed_kph' => $maxSpeed,
            'ping_count' => $pointCount,
        ]);

        // Posición en vivo (última lectura del recorrido): es lo que lee el mapa de flota en vivo.
        // Idempotente por vehicle_id (PK).
        VehiclePositionRecord::query()->updateOrCreate(
            ['vehicle_id' => $vehicleId],
            [
                'lat' => $lastLat,
                'lng' => $lastLng,
                'speed_kph' => 20.0,
                'heading' => 135,
                'ignition' => true,
                'moving' => true,
                'route_id' => null,
                'recorded_at' => $startedAt->add(new DateInterval('PT'.$durationS.'S')),
            ],
        );
    }
}

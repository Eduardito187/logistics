<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Infrastructure\LiveState;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;
use Logistics\Shared\Domain\Clock\Clock;
use Logistics\Telemetry\Application\Contract\FleetDirectory;
use Logistics\Telemetry\Application\ReadModel\VehicleLiveView;
use Logistics\Telemetry\Domain\Model\VehiclePing;
use Throwable;

/**
 * Arma, a partir del ping recién ingerido, el MISMO objeto que entrega `GET /app/telemetry/fleet/live` por
 * vehículo (`VehicleLiveView::toArray()`), para difundirlo en el evento `fleet.position`.
 *
 * Sin consulta pesada por ping: la posición sale del propio ping y los datos de referencia (placa, sede,
 * chofer del día, nombre del chofer) se leen por el mismo puerto en lote que usa la consulta
 * (`FleetDirectory`) y se guardan en caché CORTA (`live.reference_ttl_seconds`) en forma primitiva (arrays).
 * Con la caché tibia, armar el objeto no toca la base. Nada de estáticas memoizadoras: el estado vive en el
 * store de caché, así que es seguro con workers persistentes.
 *
 * Tolerante: si el store de caché no responde, se lee directo del directorio (que ya degrada a null).
 *
 * @phpstan-type FleetSnapshot array{vehicle_id: string, lat: float, lng: float, speed_kph: float|null, heading: int|null, ignition: bool|null, moving: bool, route_id: string|null, driver_id: string|null, recorded_at: string}
 * @phpstan-type LiveFleetObject array{vehicle_id: string, lat: float, lng: float, speed_kph: float|null, heading: int|null, ignition: bool|null, moving: bool, route_id: string|null, recorded_at: string, plate: string|null, driver_id: string|null, driver_name: string|null, updated_at: string|null, base_node_id: string|null, base_node_name: string|null, city: string|null}
 */
final readonly class LiveFleetViewFactory
{
    /** Prefijo de la referencia del vehículo (placa + sede). */
    private const string VEHICLE_KEY = 'telemetry:live:ref:vehicle:';

    /** Prefijo del chofer asignado a la ruta del día del vehículo. */
    private const string ASSIGNED_KEY = 'telemetry:live:ref:assigned:';

    /** Prefijo del nombre del chofer. */
    private const string DRIVER_KEY = 'telemetry:live:ref:driver:';

    /** Formato del instante de la lectura en su forma primitiva (con microsegundos y offset). */
    private const string INSTANT_FORMAT = 'Y-m-d\TH:i:s.uP';

    public function __construct(
        private FleetDirectory $fleet,
        private Clock $clock,
    ) {}

    /**
     * Objeto de `fleet/live` del vehículo del ping (forma primitiva).
     *
     * `recorded_at` va en la zona de la app (igual que la consulta) y `updated_at` es la hora del servidor
     * en que se arma el objeto, o sea ahora.
     *
     * @return array{vehicle_id: string, lat: float, lng: float, speed_kph: float|null, heading: int|null, ignition: bool|null, moving: bool, route_id: string|null, recorded_at: string, plate: string|null, driver_id: string|null, driver_name: string|null, updated_at: string|null, base_node_id: string|null, base_node_name: string|null, city: string|null}
     */
    public function fromPing(VehiclePing $ping, bool $moving): array
    {
        return $this->fromSnapshot($this->snapshot($ping, $moving));
    }

    /**
     * Forma primitiva de la lectura (sin datos de referencia): lo mínimo para rearmar el objeto de
     * `fleet/live` más tarde. Es lo que se guarda como posición pendiente cuando el throttle la descarta
     * (se cachea un array, nunca el objeto de dominio). `recorded_at` lleva microsegundos y offset.
     *
     * @return FleetSnapshot
     */
    public function snapshot(VehiclePing $ping, bool $moving): array
    {
        return [
            'vehicle_id' => $ping->vehicleId(),
            'lat' => $ping->point()->lat,
            'lng' => $ping->point()->lng,
            'speed_kph' => $ping->speed()?->kph,
            'heading' => $ping->heading()?->degrees,
            'ignition' => $ping->ignition(),
            'moving' => $moving,
            'route_id' => $ping->routeId(),
            'driver_id' => $ping->driverId(),
            'recorded_at' => $ping->recordedAt()->format(self::INSTANT_FORMAT),
        ];
    }

    /**
     * Objeto de `fleet/live` a partir de la forma primitiva de una lectura (ver `snapshot()`), con los datos
     * de referencia vigentes al momento de armarlo.
     *
     * @param  FleetSnapshot   $snapshot
     * @return LiveFleetObject
     */
    public function fromSnapshot(array $snapshot): array
    {
        $vehicleId = $snapshot['vehicle_id'];
        $appZone = new DateTimeZone(date_default_timezone_get());
        $now = $this->clock->now();

        $reference = $this->vehicleReference($vehicleId);

        // Chofer: el de la lectura; si no vino, el asignado a la ruta del día del vehículo.
        $driverId = $snapshot['driver_id'] ?? $this->assignedDriverId($vehicleId, $now);
        $driverName = $driverId !== null ? $this->driverName($driverId) : null;

        return VehicleLiveView::fromRow(
            [
                'vehicle_id' => $vehicleId,
                'lat' => $snapshot['lat'],
                'lng' => $snapshot['lng'],
                'speed_kph' => $snapshot['speed_kph'],
                'heading' => $snapshot['heading'],
                'ignition' => $snapshot['ignition'],
                'moving' => $snapshot['moving'],
                'route_id' => $snapshot['route_id'],
                'recorded_at' => (new DateTimeImmutable($snapshot['recorded_at']))->setTimezone($appZone),
                'driver_id' => $driverId,
                'updated_at' => $now->setTimezone($appZone),
            ],
            $reference['plate'],
            $driverId,
            $driverName,
            $reference['base'],
        )->toArray();
    }

    /**
     * ¿El vehículo está dado de baja en flota? Sale de la misma referencia en caché corta que la placa, así
     * que no cuesta una lectura por ping. Un camión de baja ya no difunde `fleet.position`.
     */
    public function isRetired(string $vehicleId): bool
    {
        return $this->vehicleReference($vehicleId)['retired'];
    }

    /**
     * Olvida la referencia en caché del vehículo (placa, sede, baja): la próxima lectura va a la base. Lo usa
     * la reacción a la baja del camión, para que surta efecto sin esperar al vencimiento de la caché.
     */
    public function forgetVehicle(string $vehicleId): void
    {
        try {
            $this->store()->forget(self::VEHICLE_KEY.$vehicleId);
        } catch (Throwable) {
            // Sin store no hay nada que olvidar: la referencia vence sola.
        }
    }

    /**
     * Placa, sede y baja del vehículo (lecturas por id, cacheadas juntas).
     *
     * @return array{plate: string|null, base: array{node_id: string, node_name: string|null, department: string|null}|null, retired: bool}
     */
    private function vehicleReference(string $vehicleId): array
    {
        $read = fn (): array => [
            'plate' => $this->fleet->plates([$vehicleId])[$vehicleId] ?? null,
            'base' => $this->fleet->homeBases([$vehicleId])[$vehicleId] ?? null,
            'retired' => $this->fleet->retiredVehicleIds([$vehicleId]) !== [],
        ];

        /** @var array{plate: string|null, base: array{node_id: string, node_name: string|null, department: string|null}|null, retired?: bool} $reference */
        $reference = $this->cached(self::VEHICLE_KEY.$vehicleId, $read);

        // Una entrada guardada antes de existir la marca de baja no la trae: se toma como "no dado de baja".
        return [
            'plate' => $reference['plate'],
            'base' => $reference['base'],
            'retired' => $reference['retired'] ?? false,
        ];
    }

    /** Chofer asignado a la ruta de hoy (día operativo local) del vehículo, o null. */
    private function assignedDriverId(string $vehicleId, DateTimeImmutable $now): ?string
    {
        $today = $now
            ->setTimezone(new DateTimeZone((string) config('logistics-telemetry.working_hours.timezone', 'America/La_Paz')))
            ->setTime(0, 0);

        $read = fn (): array => [
            'driver_id' => $this->fleet->assignedDriverIds([$vehicleId], $today)[$vehicleId] ?? null,
        ];

        /** @var array{driver_id: string|null} $assigned */
        $assigned = $this->cached(self::ASSIGNED_KEY.$vehicleId.':'.$today->format('Y-m-d'), $read);

        return $assigned['driver_id'];
    }

    /** Nombre del chofer, o null si no se pudo resolver. */
    private function driverName(string $driverId): ?string
    {
        $read = fn (): array => [
            'name' => $this->fleet->driverNames([$driverId])[$driverId] ?? null,
        ];

        /** @var array{name: string|null} $driver */
        $driver = $this->cached(self::DRIVER_KEY.$driverId, $read);

        return $driver['name'];
    }

    /**
     * Lee de la caché corta o, si falta, resuelve y guarda. El valor es siempre un array (forma primitiva;
     * así también se cachea el "no encontrado"). Si el store no responde, resuelve directo.
     *
     * @param  callable(): array<string, mixed> $read
     * @return array<string, mixed>
     */
    private function cached(string $key, callable $read): array
    {
        try {
            $store = $this->store();
            $hit = $store->get($key);
            if (is_array($hit)) {
                /** @var array<string, mixed> $hit */
                return $hit;
            }
        } catch (Throwable) {
            return $read();
        }

        $value = $read();

        try {
            $store->put($key, $value, $this->ttlSeconds());
        } catch (Throwable) {
            // La caché es un acelerador: sin ella el objeto se arma igual.
        }

        return $value;
    }

    /** El mismo store del estado caliente (Valkey por defecto). */
    private function store(): CacheRepository
    {
        /** @var string $store */
        $store = config('logistics-telemetry.live.cache_store', 'redis');

        return Cache::store($store);
    }

    private function ttlSeconds(): int
    {
        return max(1, (int) config('logistics-telemetry.live.reference_ttl_seconds', 60));
    }
}

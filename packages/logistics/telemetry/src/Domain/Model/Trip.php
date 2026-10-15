<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Domain\Model;

use DateInterval;
use DateTimeImmutable;
use Logistics\Shared\Domain\Aggregate\AggregateRoot;
use Logistics\Telemetry\Domain\Event\TripCompleted;
use Logistics\Telemetry\Domain\Event\TripStarted;
use Logistics\Telemetry\Domain\ValueObject\GeoPoint;
use Logistics\Telemetry\Domain\ValueObject\Speed;
use Logistics\Telemetry\Domain\ValueObject\TripId;
use Logistics\Telemetry\Domain\ValueObject\TripStatus;

/**
 * Trip: un tramo continuo de conducción de un vehículo, delimitado por huecos temporales (ver
 * TripTracker). Acumula métricas de forma incremental a medida que llegan pings: distancia recorrida
 * (haversine punto a punto), duración, tiempo en movimiento vs detenido, velocidad máxima y cantidad de
 * lecturas. Así no hay que recorrer los millones de pings para resumir un recorrido.
 *
 * Solo emite eventos de negocio relevantes: TripStarted al abrir y TripCompleted al cerrar. NO emite un
 * evento por punto (volumen): addPoint muta el estado en silencio.
 */
final class Trip extends AggregateRoot
{
    private function __construct(
        private readonly TripId $id,
        private readonly string $vehicleId,
        private readonly ?string $driverId,
        private readonly ?string $routeId,
        private TripStatus $status,
        private readonly DateTimeImmutable $startedAt,
        private ?DateTimeImmutable $endedAt,
        private readonly GeoPoint $startPoint,
        private ?GeoPoint $endPoint,
        private float $distanceM,
        private int $durationS,
        private int $movingS,
        private int $idleS,
        private float $maxSpeedKph,
        private int $pingCount,
    ) {}

    /**
     * Abre un trip nuevo en la posición y el instante del primer ping. Arranca activo y sin métricas.
     */
    public static function start(
        TripId $id,
        string $vehicleId,
        ?string $driverId,
        ?string $routeId,
        GeoPoint $startPoint,
        DateTimeImmutable $at,
    ): self {
        $trip = new self(
            $id,
            $vehicleId,
            $driverId,
            $routeId,
            TripStatus::Active,
            $at,
            null,
            $startPoint,
            $startPoint,
            0.0,
            0,
            0,
            0,
            0.0,
            0,
        );

        $trip->recordEvent(new TripStarted(
            $id->toString(),
            $vehicleId,
            $routeId,
            $at->format(DATE_ATOM),
        ));

        return $trip;
    }

    /**
     * Reconstituye un trip desde persistencia, sin grabar eventos (ya ocurrieron).
     */
    public static function reconstitute(
        TripId $id,
        string $vehicleId,
        ?string $driverId,
        ?string $routeId,
        TripStatus $status,
        DateTimeImmutable $startedAt,
        ?DateTimeImmutable $endedAt,
        GeoPoint $startPoint,
        ?GeoPoint $endPoint,
        float $distanceM,
        int $durationS,
        int $movingS,
        int $idleS,
        float $maxSpeedKph,
        int $pingCount,
    ): self {
        return new self(
            $id,
            $vehicleId,
            $driverId,
            $routeId,
            $status,
            $startedAt,
            $endedAt,
            $startPoint,
            $endPoint,
            $distanceM,
            $durationS,
            $movingS,
            $idleS,
            $maxSpeedKph,
            $pingCount,
        );
    }

    /**
     * Agrega un punto al trip y acumula sus métricas. Sin evento (volumen).
     *
     * - distancia: haversine desde el último punto conocido hasta el nuevo.
     * - duración: segundos desde el inicio hasta este punto.
     * - movimiento/inactividad: el tiempo transcurrido desde el punto anterior se suma a `movingS` si la
     *   velocidad supera el umbral, o a `idleS` en caso contrario.
     * - velocidad máxima y cantidad de puntos.
     */
    public function addPoint(GeoPoint $point, ?Speed $speed, DateTimeImmutable $at, float $movingThresholdKph = 3.0): void
    {
        $lastPoint = $this->endPoint ?? $this->startPoint;
        $this->distanceM += $lastPoint->distanceMetersTo($point);

        $previousDurationS = $this->durationS;
        $newDurationS = max(0, $at->getTimestamp() - $this->startedAt->getTimestamp());
        $deltaS = max(0, $newDurationS - $previousDurationS);
        $this->durationS = $newDurationS;

        if ($speed !== null && $speed->isMoving($movingThresholdKph)) {
            $this->movingS += $deltaS;
        } else {
            $this->idleS += $deltaS;
        }

        if ($speed !== null) {
            $this->maxSpeedKph = max($this->maxSpeedKph, $speed->kph);
        }

        $this->endPoint = $point;
        $this->pingCount++;
    }

    /**
     * Cierra el trip. Idempotente: si ya está completado, no hace nada ni graba evento.
     */
    public function complete(DateTimeImmutable $at): void
    {
        if ($this->status === TripStatus::Completed) {
            return;
        }

        $this->status = TripStatus::Completed;
        $this->endedAt = $at;

        $this->recordEvent(new TripCompleted(
            $this->id->toString(),
            $this->vehicleId,
            $this->routeId,
            $this->distanceM,
            $this->durationS,
            $this->movingS,
            $this->idleS,
            $this->maxSpeedKph,
            $this->pingCount,
            $this->startedAt->format(DATE_ATOM),
            $at->format(DATE_ATOM),
        ));
    }

    public function isActive(): bool
    {
        return $this->status === TripStatus::Active;
    }

    /**
     * Instante del último punto acumulado. Se deriva del inicio más la duración (no se persiste aparte):
     * lo usa TripTracker para medir el hueco temporal contra el siguiente ping.
     */
    public function lastPointAt(): DateTimeImmutable
    {
        return $this->startedAt->add(new DateInterval('PT'.$this->durationS.'S'));
    }

    public function id(): TripId
    {
        return $this->id;
    }

    public function vehicleId(): string
    {
        return $this->vehicleId;
    }

    public function driverId(): ?string
    {
        return $this->driverId;
    }

    public function routeId(): ?string
    {
        return $this->routeId;
    }

    public function status(): TripStatus
    {
        return $this->status;
    }

    public function startedAt(): DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function endedAt(): ?DateTimeImmutable
    {
        return $this->endedAt;
    }

    public function startPoint(): GeoPoint
    {
        return $this->startPoint;
    }

    public function endPoint(): ?GeoPoint
    {
        return $this->endPoint;
    }

    public function distanceM(): float
    {
        return $this->distanceM;
    }

    public function durationS(): int
    {
        return $this->durationS;
    }

    public function movingS(): int
    {
        return $this->movingS;
    }

    public function idleS(): int
    {
        return $this->idleS;
    }

    public function maxSpeedKph(): float
    {
        return $this->maxSpeedKph;
    }

    public function pingCount(): int
    {
        return $this->pingCount;
    }
}

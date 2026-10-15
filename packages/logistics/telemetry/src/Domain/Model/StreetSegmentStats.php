<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Domain\Model;

use DateTimeImmutable;
use Logistics\Shared\Domain\Aggregate\AggregateRoot;
use Logistics\Telemetry\Domain\Exception\TelemetryException;
use Logistics\Telemetry\Domain\ValueObject\StreetSegmentId;

/**
 * Estadística acumulada de un segmento de calle: cuántas veces se transitó, a qué velocidad promedio y
 * mínima, cuánto tiempo total y en qué horas del día. Alimenta el mapa de calles transitadas vs libres.
 *
 * La identidad estable del tramo es `segmentKey` (hash de los nodos OSRM); el id del agregado es interno.
 * Los promedios se mantienen de forma incremental (como el rating de Carrier): no se recorre el histórico,
 * cada muestra actualiza el promedio y el mínimo in situ.
 *
 * NO emite eventos de dominio a propósito: la agregación es masiva (una traza puede aportar cientos de
 * muestras por corrida del worker) y publicar un evento por muestra inundaría el outbox sin aportar un
 * hecho de negocio accionable. Si en el futuro se necesita reaccionar a congestión, se añadiría un evento
 * acotado (p. ej. `telemetry.street_congested`), no uno por muestra.
 */
final class StreetSegmentStats extends AggregateRoot
{
    /**
     * @param list<array{lat: float, lng: float}> $geometry
     * @param list<int>                           $hourHistogram 24 enteros: muestras por hora (0–23)
     */
    private function __construct(
        private readonly StreetSegmentId $id,
        private readonly string $segmentKey,
        private ?string $name,
        private array $geometry,
        private int $sampleCount,
        private float $avgSpeedKph,
        private float $minSpeedKph,
        private int $totalSeconds,
        private ?DateTimeImmutable $lastSampledAt,
        private array $hourHistogram,
    ) {}

    /**
     * Inicia la estadística de un segmento nuevo, sin muestras todavía.
     *
     * @param list<array{lat: float, lng: float}> $geometry
     */
    public static function start(
        StreetSegmentId $id,
        string $segmentKey,
        ?string $name,
        array $geometry,
    ): self {
        $segmentKey = trim($segmentKey);
        if ($segmentKey === '') {
            throw TelemetryException::because('La clave del segmento de calle no puede estar vacía.');
        }

        return new self(
            $id,
            $segmentKey,
            $name,
            array_values($geometry),
            0,
            0.0,
            0.0,
            0,
            null,
            array_fill(0, 24, 0),
        );
    }

    /**
     * Reconstituye la estadística desde persistencia, sin grabar eventos (ya ocurrieron).
     *
     * @param list<array{lat: float, lng: float}> $geometry
     * @param list<int>                           $hourHistogram
     */
    public static function reconstitute(
        StreetSegmentId $id,
        string $segmentKey,
        ?string $name,
        array $geometry,
        int $sampleCount,
        float $avgSpeedKph,
        float $minSpeedKph,
        int $totalSeconds,
        ?DateTimeImmutable $lastSampledAt,
        array $hourHistogram,
    ): self {
        return new self(
            $id,
            $segmentKey,
            $name,
            array_values($geometry),
            $sampleCount,
            $avgSpeedKph,
            $minSpeedKph,
            $totalSeconds,
            $lastSampledAt,
            self::normalizeHistogram($hourHistogram),
        );
    }

    /**
     * Acumula una muestra del tramo: recalcula el promedio y el mínimo de velocidad de forma incremental,
     * suma el tiempo y marca la hora en el histograma.
     *
     * El parámetro `$at` (momento de la muestra) es opcional para no romper la firma del contrato: cuando
     * se pasa, actualiza `lastSampledAt` (el dominio no usa reloj global; la hora se recibe por parámetro).
     */
    public function addSample(float $speedKph, int $seconds, int $hour, ?DateTimeImmutable $at = null): void
    {
        if ($speedKph < 0.0) {
            throw TelemetryException::because("La velocidad de la muestra no puede ser negativa: {$speedKph}");
        }
        if ($hour < 0 || $hour > 23) {
            throw TelemetryException::because("Hora fuera de rango (0–23): {$hour}");
        }

        $this->minSpeedKph = $this->sampleCount === 0
            ? $speedKph
            : min($this->minSpeedKph, $speedKph);

        $this->avgSpeedKph = round(
            (($this->avgSpeedKph * $this->sampleCount) + $speedKph) / ($this->sampleCount + 1),
            2,
        );

        $this->totalSeconds += max(0, $seconds);
        // Se reconstruye la lista para que PHPStan conserve el tipo `list<int>` tras el incremento por índice.
        $histogram = $this->hourHistogram;
        $histogram[$hour]++;
        $this->hourHistogram = array_values($histogram);
        $this->sampleCount++;

        if ($at !== null) {
            $this->lastSampledAt = $at;
        }
    }

    public function id(): StreetSegmentId
    {
        return $this->id;
    }

    public function segmentKey(): string
    {
        return $this->segmentKey;
    }

    public function name(): ?string
    {
        return $this->name;
    }

    /**
     * @return list<array{lat: float, lng: float}>
     */
    public function geometry(): array
    {
        return $this->geometry;
    }

    public function sampleCount(): int
    {
        return $this->sampleCount;
    }

    public function averageSpeedKph(): float
    {
        return $this->avgSpeedKph;
    }

    public function minSpeedKph(): float
    {
        return $this->minSpeedKph;
    }

    public function totalSeconds(): int
    {
        return $this->totalSeconds;
    }

    public function lastSampledAt(): ?DateTimeImmutable
    {
        return $this->lastSampledAt;
    }

    /**
     * @return list<int>
     */
    public function hourHistogram(): array
    {
        return $this->hourHistogram;
    }

    /**
     * Normaliza el histograma leído de persistencia a exactamente 24 enteros (0–23).
     *
     * @param  list<int> $histogram
     * @return list<int>
     */
    private static function normalizeHistogram(array $histogram): array
    {
        $normalized = array_fill(0, 24, 0);
        foreach ($histogram as $hour => $count) {
            if ($hour >= 0 && $hour <= 23) {
                $normalized[$hour] = (int) $count;
            }
        }

        return $normalized;
    }
}

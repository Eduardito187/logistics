<?php

declare(strict_types=1);

namespace Logistics\Execution\Domain\ValueObject;

use DateTimeImmutable;
use Logistics\Execution\Domain\Exception\ExecutionException;

/**
 * Coordenada geográfica validada (latitud/longitud en grados decimales, WGS84) del lugar del escaneo.
 *
 * Cada escaneo en terreno trae su GPS: es parte de la evidencia inmutable del hecho. Opcionalmente lleva la
 * precisión del fix (metros) y el momento en que el teléfono lo capturó (puede ser anterior al escaneo).
 *
 * La "isla nula" (0,0) NUNCA se acepta como posición real: es lo que manda un teléfono sin señal. Para eso
 * está fromNullable(), que devuelve null (posición desconocida) en vez de contaminar la auditoría geográfica.
 */
final readonly class GeoPoint
{
    private function __construct(
        public float $lat,
        public float $lng,
        public ?float $accuracyM = null,
        public ?DateTimeImmutable $capturedAt = null,
    ) {}

    public static function of(float $lat, float $lng, ?float $accuracyM = null, ?DateTimeImmutable $capturedAt = null): self
    {
        if ($lat < -90.0 || $lat > 90.0) {
            throw ExecutionException::because("latitud fuera de rango (-90..90): {$lat}");
        }
        if ($lng < -180.0 || $lng > 180.0) {
            throw ExecutionException::because("longitud fuera de rango (-180..180): {$lng}");
        }

        if ($accuracyM !== null && $accuracyM < 0.0) {
            throw ExecutionException::because("precisión GPS negativa: {$accuracyM}");
        }

        return new self($lat, $lng, $accuracyM, $capturedAt);
    }

    /**
     * Posición opcional: null si falta alguna coordenada o si es exactamente (0,0) (sin señal). Nunca se
     * persiste 0,0 como posición real.
     */
    public static function fromNullable(
        ?float $lat,
        ?float $lng,
        ?float $accuracyM = null,
        ?DateTimeImmutable $capturedAt = null,
    ): ?self {
        if ($lat === null || $lng === null || ($lat === 0.0 && $lng === 0.0)) {
            return null;
        }

        return self::of($lat, $lng, $accuracyM, $capturedAt);
    }

    public function equals(self $other): bool
    {
        return $this->lat === $other->lat && $this->lng === $other->lng;
    }
}

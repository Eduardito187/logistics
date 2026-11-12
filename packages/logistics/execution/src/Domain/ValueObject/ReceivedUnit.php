<?php

declare(strict_types=1);

namespace Logistics\Execution\Domain\ValueObject;

use DateTimeImmutable;
use Logistics\Execution\Domain\Exception\ExecutionException;

/**
 * Un bulto recibido en el nodo: el escaneo inmutable del momento en que entró. Registra qué bulto, con
 * qué GPS, por qué dispositivo/usuario y cuándo. Nunca se modifica ni se borra (append-only).
 *
 * La idempotencia offline se apoya en idempotencyKey: reintentar el mismo escaneo (sin red, reenvío)
 * trae la misma clave y no duplica el bulto recibido.
 *
 * El GPS es OPCIONAL: en interiores (depósito, trastienda) el teléfono puede no tener señal. Sin fix el
 * escaneo se registra igual con `geo` null; nunca se inventa una posición (0,0 no es una posición real).
 * Si el fix existe, puede traer su precisión (metros) y el instante en que se capturó.
 */
final readonly class ReceivedUnit
{
    private function __construct(
        public string $unitId,
        public ?GeoPoint $geo,
        public DeviceContext $device,
        public DateTimeImmutable $occurredAt,
        public string $idempotencyKey,
        public ?float $accuracyM = null,
        public ?DateTimeImmutable $locationCapturedAt = null,
    ) {}

    public static function record(
        string $unitId,
        ?GeoPoint $geo,
        DeviceContext $device,
        DateTimeImmutable $occurredAt,
        string $idempotencyKey,
        ?float $accuracyM = null,
        ?DateTimeImmutable $locationCapturedAt = null,
    ): self {
        $unit = trim($unitId);
        if ($unit === '') {
            throw ExecutionException::because('la recepción de un producto requiere el id del producto (unitId)');
        }

        $key = trim($idempotencyKey);
        if ($key === '') {
            throw ExecutionException::because('la recepción de un producto requiere una clave de idempotencia del dispositivo');
        }

        if ($accuracyM !== null && $accuracyM < 0.0) {
            throw ExecutionException::because('la precisión del GPS no puede ser negativa');
        }

        // Sin posición no hay precisión ni instante de captura que guardar.
        if ($geo === null) {
            $accuracyM = null;
            $locationCapturedAt = null;
        }

        return new self($unit, $geo, $device, $occurredAt, $key, $accuracyM, $locationCapturedAt);
    }
}

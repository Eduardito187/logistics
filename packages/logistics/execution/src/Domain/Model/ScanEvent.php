<?php

declare(strict_types=1);

namespace Logistics\Execution\Domain\Model;

use DateTimeImmutable;
use Logistics\Execution\Domain\Exception\ExecutionException;
use Logistics\Execution\Domain\ValueObject\DeviceContext;
use Logistics\Execution\Domain\ValueObject\GeoPoint;
use Logistics\Execution\Domain\ValueObject\ScanEventId;
use Logistics\Execution\Domain\ValueObject\ScanType;

/**
 * Un escaneo en terreno: el ladrillo inmutable de la ejecución. Registra qué bulto, en qué etapa, con
 * qué GPS, por qué dispositivo/usuario y cuándo. Nunca se modifica ni se borra (append-only).
 *
 * La idempotencia offline se apoya en idempotencyKey: la clave que genera el dispositivo por evento.
 * Reintentar el mismo escaneo (sin red, reenvío) trae la misma clave y no duplica el hecho.
 */
final readonly class ScanEvent
{
    private function __construct(
        public ScanEventId $id,
        public string $deliveryId,
        public ScanType $type,
        public string $unitId,
        public GeoPoint $geo,
        public DeviceContext $device,
        public DateTimeImmutable $occurredAt,
        public string $idempotencyKey,
    ) {}

    public static function record(
        ScanEventId $id,
        string $deliveryId,
        ScanType $type,
        string $unitId,
        GeoPoint $geo,
        DeviceContext $device,
        DateTimeImmutable $occurredAt,
        string $idempotencyKey,
    ): self {
        if (trim($unitId) === '') {
            throw ExecutionException::because('el escaneo requiere el id del bulto (unitId)');
        }
        if (trim($idempotencyKey) === '') {
            throw ExecutionException::because('el escaneo requiere una clave de idempotencia del dispositivo');
        }

        return new self($id, $deliveryId, $type, $unitId, $geo, $device, $occurredAt, $idempotencyKey);
    }
}

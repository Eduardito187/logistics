<?php

declare(strict_types=1);

namespace Logistics\Planning\Application\ReadModel;

use Logistics\Planning\Domain\ValueObject\GeoPoint;
use Throwable;

/**
 * Lo que Planificación necesita saber de un envío para MOSTRARLO y para ARMAR un viaje: sus datos
 * legibles (pedido, cliente, dirección corta, modalidad, unidades), la hora que eligió el cliente, su
 * ubicación, su departamento de destino y su carga. Solo primitivos; lo arma el adaptador de
 * Infrastructure leyendo Envíos en solo lectura y en lote.
 *
 * - `windowStart` / `windowEnd`: horas de reloj 'HH:MM' (o null si el cliente no eligió hora).
 * - `latitude` / `longitude`: null cuando el envío no tiene ubicación utilizable.
 * - `weightKg` / `volumeM3`: 0.0 cuando la lectura no pidió la carga.
 */
final readonly class ShipmentPlanningFacts
{
    public const MODE_TRANSFER = 'transfer';

    public function __construct(
        public string $shipmentId,
        public ?string $orderReference,
        public ?string $customerName,
        public ?string $address,
        public ?string $shortAddress,
        public string $deliveryMode,
        public int $unitsTotal,
        public ?string $windowStart = null,
        public ?string $windowEnd = null,
        public ?float $latitude = null,
        public ?float $longitude = null,
        public ?string $department = null,
        public float $weightKg = 0.0,
        public float $volumeM3 = 0.0,
    ) {}

    /**
     * Punto de entrega, o null si el envío no tiene ubicación (o está fuera de rango).
     */
    public function point(): ?GeoPoint
    {
        if ($this->latitude === null || $this->longitude === null) {
            return null;
        }

        try {
            return GeoPoint::of($this->latitude, $this->longitude);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Traspaso interno de mercadería: no entrega a un cliente (exento de la regla de un solo departamento).
     */
    public function isTransfer(): bool
    {
        return $this->deliveryMode === self::MODE_TRANSFER;
    }

    /**
     * Nombre legible del pedido para mensajes al planificador (nunca un identificador interno).
     */
    public function label(): string
    {
        if ($this->orderReference !== null) {
            return 'el pedido '.$this->orderReference;
        }

        return $this->customerName !== null ? 'el pedido de '.$this->customerName : 'un pedido';
    }
}

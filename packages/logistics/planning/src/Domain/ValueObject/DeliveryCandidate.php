<?php

declare(strict_types=1);

namespace Logistics\Planning\Domain\ValueObject;

use Logistics\Planning\Domain\Exception\PlanningException;

/**
 * Pedido pendiente candidato a entrar en un plan, visto por el motor de empaque. Trae lo que el motor
 * necesita para decidir: carga (kg/m3), ubicación, departamento (regla geográfica), zona (para agrupar
 * cercanía) y franja horaria elegida por el cliente (modo slots). La foto legible del destino (pedido,
 * cliente, dirección) viaja para armar el plan sin reconsultar envíos.
 *
 * Las coordenadas y la carga las aporta quien conoce el envío; planificación no consulta otros
 * contextos desde el dominio. Es un dato puro, inmutable.
 */
final readonly class DeliveryCandidate
{
    private function __construct(
        public string $shipmentId,
        public float $latitude,
        public float $longitude,
        public float $weightKg,
        public float $volumeM3,
        public ?string $departmentId,
        public string $zoneKey,
        public ?string $deliverySlot,
        public ?string $orderReference,
        public ?string $customerName,
        public ?string $address,
    ) {}

    public static function of(
        string $shipmentId,
        float $latitude,
        float $longitude,
        float $weightKg,
        float $volumeM3,
        ?string $departmentId = null,
        ?string $zoneKey = null,
        ?string $deliverySlot = null,
        ?string $orderReference = null,
        ?string $customerName = null,
        ?string $address = null,
    ): self {
        $trimmedId = trim($shipmentId);
        if ($trimmedId === '') {
            throw PlanningException::because('el candidato necesita un id de envío');
        }
        if ($latitude < -90.0 || $latitude > 90.0) {
            throw PlanningException::because("latitud fuera de rango (-90..90): {$latitude}");
        }
        if ($longitude < -180.0 || $longitude > 180.0) {
            throw PlanningException::because("longitud fuera de rango (-180..180): {$longitude}");
        }
        if ($weightKg < 0.0) {
            throw PlanningException::because("peso inválido (no puede ser negativo): {$weightKg}");
        }
        if ($volumeM3 < 0.0) {
            throw PlanningException::because("volumen inválido (no puede ser negativo): {$volumeM3}");
        }

        // La zona por defecto se deriva del departamento para que, sin zona explícita, el agrupamiento
        // por cercanía al menos mantenga juntos los pedidos del mismo departamento.
        $resolvedZone = $zoneKey !== null && trim($zoneKey) !== ''
            ? trim($zoneKey)
            : ($departmentId ?? 'unknown');

        $resolvedSlot = $deliverySlot !== null && trim($deliverySlot) !== '' ? trim($deliverySlot) : null;

        return new self(
            $trimmedId,
            $latitude,
            $longitude,
            $weightKg,
            $volumeM3,
            $departmentId,
            $resolvedZone,
            $resolvedSlot,
            $orderReference,
            $customerName,
            $address,
        );
    }

    /**
     * Clave de partición del departamento para el empaque. Un pedido sin departamento resuelto cae en
     * su propia partición ('unknown') y nunca se mezcla con uno de departamento conocido.
     */
    public function departmentKey(): string
    {
        return $this->departmentId ?? 'unknown';
    }

    /**
     * Clave de la franja para el modo slots. Sin franja elegida, cae en 'unscheduled'.
     */
    public function slotKey(): string
    {
        return $this->deliverySlot ?? 'unscheduled';
    }
}

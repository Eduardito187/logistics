<?php

declare(strict_types=1);

namespace Logistics\Planning\Domain\ValueObject;

use DateTimeImmutable;
use Logistics\Planning\Domain\Exception\PlanningException;

/**
 * Pedido pendiente candidato a entrar en un plan, visto por el motor de empaque. Trae lo que el motor
 * necesita para decidir: carga (kg/m3), ubicación, departamento (regla geográfica), zona (para agrupar
 * cercanía) y franja horaria elegida por el cliente (modo slots). La foto legible del destino (pedido,
 * cliente, dirección) viaja para armar el plan sin reconsultar envíos.
 *
 * La franja de entrega viaja en dos formas complementarias: `deliverySlot` es la CLAVE de agrupamiento
 * (un camión sirve una sola franja) y `windowStart`/`windowEnd` son la VENTANA horaria explícita ('HH:MM')
 * que el ruteador usa como restricción para no agendar una parada antes ni después de su franja. Ambas son
 * opcionales: sin franja, el pedido se rutea solo por cercanía (comportamiento de siempre).
 *
 * Las coordenadas y la carga las aporta quien conoce el envío; planificación no consulta otros
 * contextos desde el dominio. Es un dato puro, inmutable.
 */
final readonly class DeliveryCandidate
{
    /** Patrón de hora 'HH:MM' en 24h, igual al de la franja de envíos. */
    private const string TIME_PATTERN = '/^([01]\d|2[0-3]):[0-5]\d$/';

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
        public ?string $windowStart,
        public ?string $windowEnd,
        public ShipmentRouteRequirement $requirement,
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
        ?string $windowStart = null,
        ?string $windowEnd = null,
        ?ShipmentRouteRequirement $requirement = null,
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

        [$resolvedStart, $resolvedEnd] = self::resolveWindow($windowStart, $windowEnd);

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
            $resolvedStart,
            $resolvedEnd,
            // Sin requisito explícito, el envío no exige nada al camión (comportamiento previo).
            $requirement ?? ShipmentRouteRequirement::none(),
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

    /**
     * ¿El candidato trae una ventana horaria explícita que el ruteador deba respetar?
     */
    public function hasDeliveryWindow(): bool
    {
        return $this->windowStart !== null && $this->windowEnd !== null;
    }

    /**
     * Traduce la franja 'HH:MM' del cliente a una ventana en SEGUNDOS desde la salida del depósito, que es
     * el reloj que usa el ruteador. La apertura se recorta a 0 (nunca negativa: si la franja ya abrió a la
     * salida, abre "ya"); el cierre se corre, como mínimo, a la apertura (una franja ya vencida a la salida
     * queda como una ventana instantánea que el solver tratará como retraso). Devuelve null sin ventana.
     *
     * No es I/O: solo convierte horas de reloj a segundos relativos usando la hora de salida que le pasan.
     */
    public function deliveryWindow(DateTimeImmutable $departAt): ?TimeWindow
    {
        if ($this->windowStart === null || $this->windowEnd === null) {
            return null;
        }

        $departSecond = (int) $departAt->format('H') * 3600 + (int) $departAt->format('i') * 60 + (int) $departAt->format('s');

        $earliest = (float) max(0, self::secondOfDay($this->windowStart) - $departSecond);
        $latest = (float) (self::secondOfDay($this->windowEnd) - $departSecond);
        if ($latest < $earliest) {
            $latest = $earliest;
        }

        return TimeWindow::of($earliest, $latest);
    }

    /**
     * Normaliza y valida la ventana: o vienen las dos horas en 'HH:MM' y la apertura es anterior al cierre,
     * o se descarta por completo (ambas null). No se acepta media ventana.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private static function resolveWindow(?string $windowStart, ?string $windowEnd): array
    {
        if ($windowStart === null || $windowEnd === null) {
            return [null, null];
        }

        $start = trim($windowStart);
        $end = trim($windowEnd);
        if ($start === '' || $end === '') {
            return [null, null];
        }
        if (preg_match(self::TIME_PATTERN, $start) !== 1 || preg_match(self::TIME_PATTERN, $end) !== 1) {
            throw PlanningException::because("ventana de entrega con formato inválido (HH:MM): {$start}-{$end}");
        }
        if (self::secondOfDay($start) >= self::secondOfDay($end)) {
            throw PlanningException::because("la ventana de entrega debe empezar antes de terminar: {$start}-{$end}");
        }

        return [$start, $end];
    }

    /**
     * Segundos desde la medianoche de una hora 'HH:MM' ya validada por el patrón.
     */
    private static function secondOfDay(string $hhmm): int
    {
        [$h, $m] = explode(':', $hhmm);

        return ((int) $h) * 3600 + ((int) $m) * 60;
    }
}

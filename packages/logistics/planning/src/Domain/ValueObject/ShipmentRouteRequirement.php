<?php

declare(strict_types=1);

namespace Logistics\Planning\Domain\ValueObject;

use Logistics\Planning\Domain\Exception\PlanningException;

/**
 * Requisitos de RUTEO de un envío/parada que el camión debe satisfacer para poder llevarlo: el tipo de
 * carga que es, la zona a la que va, y sus dimensiones físicas relevantes (altura y peso del bulto). El
 * motor contrasta estos requisitos contra el VehicleRouteProfile del camión y DESCARTA la asignación si el
 * camión no los cumple (excede altura/peso, zona no permitida, tipo de carga no admitido).
 *
 * Dato puro e inmutable. Lo aporta quien conoce el envío (adaptador de Infrastructure); planificación no
 * consulta Envíos desde el dominio.
 *
 * COMPATIBILIDAD: un requisito "vacío" (none) no exige nada, así un envío sin requisitos entra en
 * cualquier camión y el comportamiento previo del solver no cambia.
 *
 * Semántica (null = sin requisito de ese tipo):
 *  - loadType: tipo de carga del envío (debe estar en allowedLoadTypes del camión, si ese define lista).
 *  - zone:     zona de destino (debe estar permitida y no prohibida por el camión). Puede diferir de la
 *              zoneKey de agrupamiento; por defecto, quien arma el requisito usa la misma zona.
 *  - heightM:  altura del bulto (debe caber en maxHeightM del camión, si lo define).
 *  - weightKg: peso del bulto individual (debe caber en maxWeightKg del camión, si lo define). Es el peso
 *              del bulto para el límite físico POR BULTO, independiente de la capacidad total de la ruta.
 */
final readonly class ShipmentRouteRequirement
{
    private function __construct(
        public ?string $loadType,
        public ?string $zone,
        public ?float $heightM,
        public ?float $weightKg,
    ) {}

    /**
     * Requisito vacío: no exige nada al camión. Valor por defecto que preserva el comportamiento previo.
     */
    public static function none(): self
    {
        return new self(null, null, null, null);
    }

    public static function of(
        ?string $loadType = null,
        ?string $zone = null,
        ?float $heightM = null,
        ?float $weightKg = null,
    ): self {
        if ($heightM !== null && $heightM < 0.0) {
            throw PlanningException::because("la altura del bulto no puede ser negativa: {$heightM}");
        }
        if ($weightKg !== null && $weightKg < 0.0) {
            throw PlanningException::because("el peso del bulto no puede ser negativo: {$weightKg}");
        }

        return new self(
            self::clean($loadType),
            self::clean($zone),
            $heightM,
            $weightKg,
        );
    }

    /**
     * Reconstruye el requisito desde su forma primitiva (columna jsonb), tolerante a datos ausentes.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $loadType = $data['load_type'] ?? null;
        $zone = $data['zone'] ?? null;
        $height = $data['height_m'] ?? null;
        $weight = $data['weight_kg'] ?? null;

        return self::of(
            is_string($loadType) ? $loadType : null,
            is_string($zone) ? $zone : null,
            is_numeric($height) ? (float) $height : null,
            is_numeric($weight) ? (float) $weight : null,
        );
    }

    /**
     * ¿El requisito no exige nada? Si es así, cualquier camión lo satisface.
     */
    public function isEmpty(): bool
    {
        return $this->loadType === null
            && $this->zone === null
            && $this->heightM === null
            && $this->weightKg === null;
    }

    /**
     * Forma primitiva para persistir (jsonb) y cachear sin serializar el objeto.
     *
     * @return array{load_type: string|null, zone: string|null, height_m: float|null, weight_kg: float|null}
     */
    public function toArray(): array
    {
        return [
            'load_type' => $this->loadType,
            'zone' => $this->zone,
            'height_m' => $this->heightM,
            'weight_kg' => $this->weightKg,
        ];
    }

    private static function clean(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}

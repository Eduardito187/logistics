<?php

declare(strict_types=1);

namespace Logistics\Planning\Domain\ValueObject;

use Logistics\Planning\Domain\Exception\PlanningException;

/**
 * Atributos/capacidades de RUTEO de un camión que imponen RESTRICCIONES DURAS al VRP, más allá de la
 * capacidad en kg/m3: qué tipos de carga puede transportar, a qué zonas puede (o no puede) entrar y los
 * límites físicos de altura y peso por bulto que admite (p. ej. un envío demasiado alto o pesado para
 * ese camión no entra, aunque quede cupo de peso/volumen total).
 *
 * Dato puro e inmutable, propio del contexto de Planificación. Lo aporta quien conoce la flota (adaptador
 * de Infrastructure); planificación no consulta Fleet desde el dominio. Se guarda y transporta en forma
 * primitiva (array) para poder cachearlo sin serializar objetos (regla 7 de la guía).
 *
 * COMPATIBILIDAD: un perfil "sin restricciones" (unrestricted) no descarta nada, así el comportamiento
 * previo del solver —cuando no hay restricciones definidas— no cambia.
 *
 * Semántica de cada atributo (null/[] = sin restricción de ese tipo):
 *  - allowedLoadTypes: lista blanca de tipos de carga permitidos; null = cualquier tipo.
 *  - allowedZones:     lista blanca de zonas donde puede entrar; null = cualquier zona.
 *  - forbiddenZones:   lista negra de zonas prohibidas (gana sobre la lista blanca); [] = ninguna.
 *  - maxHeightM:       altura máxima (m) del bulto que admite; null = sin límite de altura.
 *  - maxWeightKg:      peso máximo (kg) de un bulto individual que admite; null = sin límite por bulto.
 */
final readonly class VehicleRouteProfile
{
    /**
     * @param list<string>|null $allowedLoadTypes
     * @param list<string>|null $allowedZones
     * @param list<string>      $forbiddenZones
     */
    private function __construct(
        public ?array $allowedLoadTypes,
        public ?array $allowedZones,
        public array $forbiddenZones,
        public ?float $maxHeightM,
        public ?float $maxWeightKg,
    ) {}

    /**
     * Perfil sin ninguna restricción: acepta cualquier carga, cualquier zona y sin límites físicos. Es el
     * valor por defecto que preserva el comportamiento previo del solver.
     */
    public static function unrestricted(): self
    {
        return new self(null, null, [], null, null);
    }

    /**
     * @param list<string>|null $allowedLoadTypes
     * @param list<string>|null $allowedZones
     * @param list<string>      $forbiddenZones
     */
    public static function of(
        ?array $allowedLoadTypes = null,
        ?array $allowedZones = null,
        array $forbiddenZones = [],
        ?float $maxHeightM = null,
        ?float $maxWeightKg = null,
    ): self {
        if ($maxHeightM !== null && $maxHeightM <= 0.0) {
            throw PlanningException::because("la altura máxima del camión debe ser mayor que cero: {$maxHeightM}");
        }
        if ($maxWeightKg !== null && $maxWeightKg <= 0.0) {
            throw PlanningException::because("el peso máximo por producto del camión debe ser mayor que cero: {$maxWeightKg}");
        }

        return new self(
            self::normalizeList($allowedLoadTypes),
            self::normalizeList($allowedZones),
            self::normalizeList($forbiddenZones) ?? [],
            $maxHeightM,
            $maxWeightKg,
        );
    }

    /**
     * Reconstruye el perfil desde su forma primitiva persistida (columna jsonb), tolerando datos ausentes
     * o mal formados cayendo a "sin restricción" en cada atributo (nunca rompe por un perfil corrupto).
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return self::of(
            self::stringListOrNull($data['allowed_load_types'] ?? null),
            self::stringListOrNull($data['allowed_zones'] ?? null),
            self::stringListOrNull($data['forbidden_zones'] ?? null) ?? [],
            self::positiveFloatOrNull($data['max_height_m'] ?? null),
            self::positiveFloatOrNull($data['max_weight_kg'] ?? null),
        );
    }

    /**
     * ¿El perfil no impone ninguna restricción? Si es así, la verificación de factibilidad puede saltarse.
     */
    public function isUnrestricted(): bool
    {
        return $this->allowedLoadTypes === null
            && $this->allowedZones === null
            && $this->forbiddenZones === []
            && $this->maxHeightM === null
            && $this->maxWeightKg === null;
    }

    /**
     * Forma primitiva para persistir (jsonb) y cachear sin serializar el objeto.
     *
     * @return array{allowed_load_types: list<string>|null, allowed_zones: list<string>|null, forbidden_zones: list<string>, max_height_m: float|null, max_weight_kg: float|null}
     */
    public function toArray(): array
    {
        return [
            'allowed_load_types' => $this->allowedLoadTypes,
            'allowed_zones' => $this->allowedZones,
            'forbidden_zones' => $this->forbiddenZones,
            'max_height_m' => $this->maxHeightM,
            'max_weight_kg' => $this->maxWeightKg,
        ];
    }

    /**
     * Normaliza una lista de etiquetas: recorta, descarta vacíos y reindexa. Null se mantiene como null
     * (sin restricción); una lista que queda vacía tras limpiar también vuelve null (equivale a "sin lista").
     *
     * @param  list<string>|null $values
     * @return list<string>|null
     */
    private static function normalizeList(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        $clean = [];
        foreach ($values as $value) {
            $trimmed = trim($value);
            if ($trimmed !== '') {
                $clean[] = $trimmed;
            }
        }

        return $clean === [] ? null : array_values(array_unique($clean));
    }

    /**
     * Lee una lista de strings desde datos primitivos, descartando lo que no sea string. Null si el dato
     * no es una lista utilizable.
     *
     * @return list<string>|null
     */
    private static function stringListOrNull(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }

        $clean = [];
        foreach ($value as $item) {
            if (is_string($item)) {
                $clean[] = $item;
            }
        }

        return $clean === [] ? null : $clean;
    }

    private static function positiveFloatOrNull(mixed $value): ?float
    {
        if (!is_numeric($value)) {
            return null;
        }

        $float = (float) $value;

        return $float > 0.0 ? $float : null;
    }
}

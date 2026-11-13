<?php

declare(strict_types=1);

namespace Logistics\Promise\Domain\ValueObject;

/**
 * Pesos de la elección automática de ventana (Módulo 1). Cada término está en 0..1 y se combina linealmente
 * en `WindowSelector`:
 *
 *  - `preferenceWeight` → cuánto pesa honrar la preferencia horaria del cliente (restricción blanda alta).
 *  - `balanceWeight`    → balanceo de carga entre franjas (prefiere la franja con más cupo libre).
 *  - `earlyWeight`      → sesgo a franjas más tempranas del día (opcional).
 *
 * Inmutable. Los defaults priorizan la preferencia del cliente y luego el balanceo, dejando el sesgo a
 * temprano como desempate suave. Son tunables desde Configuración (sección Promise › Auto scheduling).
 */
final readonly class WindowSelectionPolicy
{
    private function __construct(
        public float $preferenceWeight,
        public float $balanceWeight,
        public float $earlyWeight,
    ) {}

    public static function of(float $preferenceWeight, float $balanceWeight, float $earlyWeight): self
    {
        return new self(
            max(0.0, $preferenceWeight),
            max(0.0, $balanceWeight),
            max(0.0, $earlyWeight),
        );
    }

    /**
     * Pesos por defecto: preferencia del cliente (0.6) por encima del balanceo (0.3) y del sesgo a
     * temprano (0.1). Funcionan sin configuración (regla "funciona en docker nuevo").
     */
    public static function default(): self
    {
        return new self(0.6, 0.3, 0.1);
    }

    /**
     * Construye los pesos desde primitivos de configuración, cayendo a los defaults cuando falten o no
     * sean numéricos.
     *
     * @param array<string, mixed> $weights
     */
    public static function fromArray(array $weights): self
    {
        $default = self::default();

        return new self(
            self::floatOr($weights['preference'] ?? null, $default->preferenceWeight),
            self::floatOr($weights['balance'] ?? null, $default->balanceWeight),
            self::floatOr($weights['early'] ?? null, $default->earlyWeight),
        );
    }

    private static function floatOr(mixed $value, float $fallback): float
    {
        return is_numeric($value) && (float) $value >= 0.0 ? (float) $value : $fallback;
    }
}

<?php

declare(strict_types=1);

namespace Logistics\Shared\Domain\Enum;

use ValueError;

/**
 * Factory CASE-INSENSITIVE para enums de estado string-backed: acepta el valor en cualquier caja
 * (MAYÚSCULA/minúscula/Mixta) y con espacios al borde, pero SIGUE VALIDANDO que el estado exista.
 *
 * Por qué: `Enum::from()`/`tryFrom()` de PHP son case-sensitive y tiran `ValueError` ante 'owned' vs 'OWNED'
 * o 'delivered' vs 'DELIVERED'. En los BORDES donde entra un string externo (API, base, eventos del outbox,
 * seeders, ingesta), la caja no siempre coincide con el backing value → explota un flujo válido. Este trait
 * normaliza la caja y resuelve contra los `cases()`; si no matchea ninguno, falla igual (no traga basura).
 *
 * Solo para enums `string`-backed. Uso: `fromLoose()` donde hoy se usa `from()`, `tryFromLoose()` donde
 * `tryFrom()`. La comparación NO afecta el backing value real: el enum sigue teniendo su caja canónica.
 */
trait CaseInsensitiveEnum
{
    /**
     * Igual que `from()`, pero ignora la caja. Valida: lanza `ValueError` si el estado no existe.
     */
    public static function fromLoose(string $value): static
    {
        return self::tryFromLoose($value)
            ?? throw new ValueError(sprintf('"%s" no es un valor válido de %s', $value, static::class));
    }

    /**
     * Igual que `tryFrom()`, pero ignora la caja. Devuelve null si el estado no existe (sigue validando).
     */
    public static function tryFromLoose(?string $value): ?static
    {
        if ($value === null) {
            return null;
        }
        $needle = strtoupper(trim($value));
        if ($needle === '') {
            return null;
        }

        foreach (self::cases() as $case) {
            if (strtoupper((string) $case->value) === $needle) {
                return $case;
            }
        }

        return null;
    }
}

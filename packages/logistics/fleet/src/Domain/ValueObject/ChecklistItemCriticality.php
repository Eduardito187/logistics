<?php

declare(strict_types=1);

namespace Logistics\Fleet\Domain\ValueObject;

/**
 * Criticidad de un ítem de la plantilla del checklist pre-salida.
 *
 * - critical: si falla, el vehículo queda NO APTO para despachar (se bloquea salida y asignación).
 * - major:    falla importante que se reporta, pero no bloquea por sí sola.
 * - minor:    observación menor.
 */
enum ChecklistItemCriticality: string
{
    case Critical = 'critical';

    case Major = 'major';

    case Minor = 'minor';

    /**
     * Texto en inglés para el admin (regla 5 de CLAUDE.md).
     */
    public function label(): string
    {
        return match ($this) {
            self::Critical => 'Critical',
            self::Major => 'Major',
            self::Minor => 'Minor',
        };
    }

    public function blocksDispatch(): bool
    {
        return $this === self::Critical;
    }
}

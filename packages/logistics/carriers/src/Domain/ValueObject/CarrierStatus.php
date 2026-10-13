<?php

declare(strict_types=1);

namespace Logistics\Carriers\Domain\ValueObject;

/**
 * Estado de un transportista tercero dentro de la plataforma.
 *
 * Es el dominio quien decide las transiciones (ver Carrier); el enum solo enumera los estados válidos.
 */
enum CarrierStatus: string
{
    /** Activo: puede recibir asignaciones y tarifas vigentes. */
    case Active = 'active';

    /** Suspendido: no se le asigna carga hasta reactivarlo. */
    case Suspended = 'suspended';

    /**
     * Texto en inglés para el admin y la consola (regla 5 de CLAUDE.md).
     */
    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Suspended => 'Suspended',
        };
    }
}

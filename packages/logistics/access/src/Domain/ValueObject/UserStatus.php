<?php

declare(strict_types=1);

namespace Logistics\Access\Domain\ValueObject;

/**
 * Estado de un usuario dentro de la plataforma.
 *
 * Es el dominio quien decide las transiciones (ver User); el enum solo enumera los estados válidos.
 */
enum UserStatus: string
{
    /** Activo: puede autenticarse y operar. */
    case Active = 'active';

    /** Suspendido: no puede autenticarse hasta reactivarlo. */
    case Suspended = 'suspended';

    /** Invitado: dado de alta pero aún sin activar su acceso. */
    case Invited = 'invited';

    /**
     * Texto en inglés para el admin y la consola (regla 5 de CLAUDE.md).
     */
    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Suspended => 'Suspended',
            self::Invited => 'Invited',
        };
    }
}

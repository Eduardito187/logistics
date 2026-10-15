<?php

declare(strict_types=1);

namespace Logistics\Access\Domain\ValueObject;

/**
 * Estado de un token de integración dentro de la plataforma.
 *
 * Es el dominio quien decide las transiciones (ver IntegrationToken); el enum solo enumera los
 * estados válidos.
 */
enum TokenStatus: string
{
    /** Activo: puede autenticar peticiones mientras no expire. */
    case Active = 'active';

    /** Revocado: ya no autentica, de forma permanente e irreversible. */
    case Revoked = 'revoked';

    /**
     * Texto en inglés para el admin y la consola (regla 5 de CLAUDE.md).
     */
    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Revoked => 'Revoked',
        };
    }
}

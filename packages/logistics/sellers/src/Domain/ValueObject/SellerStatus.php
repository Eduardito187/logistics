<?php

declare(strict_types=1);

namespace Logistics\Sellers\Domain\ValueObject;

/**
 * Estado del ciclo de vida de un seller dentro de la plataforma.
 *
 * El dominio decide las transiciones (ver Seller): nace en Onboarding, pasa a Active cuando se habilita
 * a operar y a Suspended si se lo bloquea. El enum solo enumera los estados válidos.
 */
enum SellerStatus: string
{
    /** En alta: datos cargados pero todavía no habilitado a operar. */
    case Onboarding = 'onboarding';

    /** Activo: habilitado a recibir mercadería y a que se le atribuya inventario. */
    case Active = 'active';

    /** Suspendido: bloqueado hasta que se lo reactive. */
    case Suspended = 'suspended';

    /**
     * Texto en inglés para el admin y la consola (regla 5 de CLAUDE.md).
     */
    public function label(): string
    {
        return match ($this) {
            self::Onboarding => 'Onboarding',
            self::Active => 'Active',
            self::Suspended => 'Suspended',
        };
    }
}

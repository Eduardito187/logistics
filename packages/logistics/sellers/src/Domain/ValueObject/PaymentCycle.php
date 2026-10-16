<?php

declare(strict_types=1);

namespace Logistics\Sellers\Domain\ValueObject;

/**
 * Ciclo de pago de las liquidaciones al seller: cada cuánto se le paga lo que le corresponde.
 *
 * Es parte de los términos de liquidación (ver SettlementTerms); el cálculo y la ejecución del pago
 * viven en el futuro contexto `settlement` (Fase 4), no acá.
 */
enum PaymentCycle: string
{
    /** Pago semanal. */
    case Weekly = 'weekly';

    /** Pago quincenal (cada dos semanas). */
    case Biweekly = 'biweekly';

    /** Pago mensual. */
    case Monthly = 'monthly';

    /**
     * Texto en inglés para el admin y la consola (regla 5 de CLAUDE.md).
     */
    public function label(): string
    {
        return match ($this) {
            self::Weekly => 'Weekly',
            self::Biweekly => 'Biweekly',
            self::Monthly => 'Monthly',
        };
    }
}

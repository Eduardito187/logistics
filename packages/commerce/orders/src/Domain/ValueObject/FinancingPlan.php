<?php

declare(strict_types=1);

namespace Commerce\Orders\Domain\ValueObject;

/**
 * Forma de financiamiento con la que se crea la orden. Define QUÉ se necesita para pasar a «procesando»
 * (fulfillment): el contado se promueve con el pago capturado; el crédito/minicuotas con la firma del
 * contrato (y, si lleva cuota inicial, también el pago del inicial). El plan de cuotas en sí vive en el
 * sistema de crédito/POS: la plataforma solo necesita saber qué condiciones habilitan el despacho.
 */
enum FinancingPlan: string
{
    /** Contado: se promueve a procesando con el pago capturado (comportamiento clásico). */
    case Cash = 'cash';

    /** Crédito con cuota inicial: requiere firma de contrato Y pago del inicial. */
    case CreditWithInitial = 'credit_with_initial';

    /** Crédito sin inicial: requiere solo la firma del contrato. */
    case CreditWithoutInitial = 'credit_without_initial';

    /** ¿Es una orden a crédito (cualquiera de las dos modalidades)? */
    public function isCredit(): bool
    {
        return $this === self::CreditWithInitial || $this === self::CreditWithoutInitial;
    }

    /** ¿El crédito exige pago de cuota inicial para despachar? */
    public function requiresInitial(): bool
    {
        return $this === self::CreditWithInitial;
    }

    /** Texto en inglés para el admin y la consola (regla 5 de CLAUDE.md). */
    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Cash',
            self::CreditWithInitial => 'Credit (with down payment)',
            self::CreditWithoutInitial => 'Credit (no down payment)',
        };
    }
}

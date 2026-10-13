<?php

declare(strict_types=1);

namespace Logistics\Notifications\Domain\ValueObject;

/**
 * Condición que dispara el paso al siguiente canal de la cascada.
 *
 * El caso por defecto del ADR 006 es "sin apertura": si el cliente no abrió el push ni el email, se
 * escala al canal siguiente (y recién al final, SMS). OnFailure cubre el caso en que el propio canal
 * no pudo entregar (token inválido, bounce), independientemente de la apertura.
 */
enum EscalateCondition: string
{
    /** Escalar si el cliente no abrió la notificación en el canal actual. */
    case NoOpen = 'NO_OPEN';

    /** Escalar si el canal actual no pudo entregar el mensaje. */
    case OnFailure = 'ON_FAILURE';
}

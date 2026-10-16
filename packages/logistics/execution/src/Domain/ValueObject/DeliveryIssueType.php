<?php

declare(strict_types=1);

namespace Logistics\Execution\Domain\ValueObject;

/**
 * Tipo del inconveniente que el chofer reporta durante una entrega que SIGUE EN CURSO.
 *
 * Es distinto de una "entrega fallida" (que cierra la entrega) y del incidente del camión (avería o
 * accidente): aquí la entrega no se cierra, solo se deja constancia del obstáculo para que la torre de
 * control reaccione (reordenar, avisar al cliente, etc.). Descriptivo; no dispara reacción automática.
 */
enum DeliveryIssueType: string
{
    /** Retraso: el chofer llegará tarde a esta parada. */
    case Delay = 'DELAY';

    /** Acceso: no puede llegar al punto (calle cerrada, zona restringida, portón trancado). */
    case Access = 'ACCESS';

    /** El cliente pide algo (reprogramar, esperar, otro punto de entrega). */
    case CustomerRequest = 'CUSTOMER_REQUEST';

    /** La dirección no es clara y el chofer necesita indicaciones. */
    case AddressUnclear = 'ADDRESS_UNCLEAR';

    /** Otro inconveniente no tipificado (se detalla en la nota). */
    case Other = 'OTHER';

    /**
     * Texto en inglés para el admin y la consola (regla 5 de CLAUDE.md).
     */
    public function label(): string
    {
        return match ($this) {
            self::Delay => 'Delay',
            self::Access => 'Access blocked',
            self::CustomerRequest => 'Customer request',
            self::AddressUnclear => 'Address unclear',
            self::Other => 'Other',
        };
    }
}

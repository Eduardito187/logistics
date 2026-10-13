<?php

declare(strict_types=1);

namespace Logistics\Execution\Domain\ValueObject;

/**
 * Motivo de una entrega fallida. Acompaña al evento DeliveryFailed para que tracking/notifications
 * decidan el reintento o el aviso al cliente.
 */
enum FailureReason: string
{
    /** Cliente ausente en la dirección. */
    case CustomerAbsent = 'CUSTOMER_ABSENT';

    /** Dirección incorrecta o inubicable. */
    case WrongAddress = 'WRONG_ADDRESS';

    /** El cliente rechazó la entrega. */
    case Rejected = 'REJECTED';

    /** Mercadería dañada. */
    case Damaged = 'DAMAGED';

    /** Otro motivo no tipificado. */
    case Other = 'OTHER';
}

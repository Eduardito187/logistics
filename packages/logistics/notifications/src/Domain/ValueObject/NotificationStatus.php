<?php

declare(strict_types=1);

namespace Logistics\Notifications\Domain\ValueObject;

/**
 * Estado de una notificación a lo largo de la cascada de canales.
 */
enum NotificationStatus: string
{
    /** Encolada: apunta a un canal de la política y espera despacho (por outbox). */
    case Queued = 'QUEUED';

    /** Enviada con éxito por algún canal de la cascada. */
    case Sent = 'SENT';

    /** Falló definitivamente tras agotar la cascada. */
    case Failed = 'FAILED';

    /** Suprimida: no se envía (por tope, por reglas o porque dejó de ser relevante). */
    case Suppressed = 'SUPPRESSED';
}

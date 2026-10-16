<?php

declare(strict_types=1);

namespace Logistics\Inbound\Domain\ValueObject;

/**
 * Estado del ciclo de vida de un ASN / cita de recepción.
 *
 * El dominio decide las transiciones (ver Asn): nace `Announced`, se agenda (`Scheduled`), llega el
 * camión (`Arrived`), se inicia la descarga (`Receiving`), se cierra la recepción (`Received`) y se cierra
 * el ASN (`Closed`). Desde cualquier estado no terminal se puede `Cancelled`.
 */
enum AsnStatus: string
{
    /** Anunciado: el seller declaró qué va a enviar, sin cita aún. */
    case Announced = 'announced';

    /** Agendado: tiene ventana de cita y cupo de andén reservado. */
    case Scheduled = 'scheduled';

    /** Llegó: el camión del seller se presentó en el almacén. */
    case Arrived = 'arrived';

    /** En recepción: se está descargando e inspeccionando. */
    case Receiving = 'receiving';

    /** Recibido: la recepción física con QC quedó registrada. */
    case Received = 'received';

    /** Cerrado: el ASN se dio por terminado (etiquetado y conciliado). */
    case Closed = 'closed';

    /** Cancelado: el ASN no se va a recibir. */
    case Cancelled = 'cancelled';

    /**
     * Texto en inglés para el admin y la consola (regla 5 de CLAUDE.md).
     */
    public function label(): string
    {
        return match ($this) {
            self::Announced => 'Announced',
            self::Scheduled => 'Scheduled',
            self::Arrived => 'Arrived',
            self::Receiving => 'Receiving',
            self::Received => 'Received',
            self::Closed => 'Closed',
            self::Cancelled => 'Cancelled',
        };
    }

    /**
     * Un estado terminal ya no admite más transiciones (no se puede cancelar ni avanzar).
     */
    public function isTerminal(): bool
    {
        return $this === self::Closed || $this === self::Cancelled;
    }
}

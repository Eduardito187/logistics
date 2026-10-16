<?php

declare(strict_types=1);

namespace Logistics\Returns\Domain\ValueObject;

/**
 * Estado del ciclo de vida de una devolución.
 *
 * El dominio decide las transiciones (ver ReturnOrder): nace `Initiated` (se declaró la devolución), se
 * recibe físicamente (`Received`), se decide su destino (`Dispositioned`) y se cierra (`Closed`).
 */
enum ReturnStatus: string
{
    /** Iniciada: se declaró la devolución, aún no llega al almacén. */
    case Initiated = 'initiated';

    /** Recibida: la mercadería llegó al almacén central. */
    case Received = 'received';

    /** Dispuesta: se decidió qué hacer con ella (restock / devolver al seller / descartar). */
    case Dispositioned = 'dispositioned';

    /** Cerrada: la devolución quedó conciliada y terminada. */
    case Closed = 'closed';

    /**
     * Texto en inglés para el admin y la consola (regla 5 de CLAUDE.md).
     */
    public function label(): string
    {
        return match ($this) {
            self::Initiated => 'Initiated',
            self::Received => 'Received',
            self::Dispositioned => 'Dispositioned',
            self::Closed => 'Closed',
        };
    }
}

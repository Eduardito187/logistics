<?php

declare(strict_types=1);

namespace Logistics\Execution\Domain\ValueObject;

/**
 * Estado de una parada dentro de la ruta del chofer. Pending hasta que el desenlace de la entrega del
 * envío la cierra: Completed si se entregó, Failed si el intento falló. Un estado terminal no vuelve a
 * Pending (la máquina de estados de la entrega ya gobierna los reintentos).
 */
enum RouteStopStatus: string
{
    /** Pendiente: el chofer todavía no la atendió. */
    case Pending = 'PENDING';

    /** Atendida con entrega exitosa. */
    case Completed = 'COMPLETED';

    /** Atendida con intento fallido. */
    case Failed = 'FAILED';

    /**
     * Sacada de esta ruta por un incidente MAYOR del camión: el envío se replanifica para el día
     * siguiente. No se volvió a entregar en esta ruta; es un cierre no exitoso que no cuenta como fallo.
     */
    case Rescheduled = 'RESCHEDULED';

    /**
     * ¿Es un estado terminal (ya no pendiente)?
     */
    public function isTerminal(): bool
    {
        return $this !== self::Pending;
    }
}

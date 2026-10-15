<?php

declare(strict_types=1);

namespace Logistics\Execution\Domain\ValueObject;

/**
 * Estado de la ruta del chofer a lo largo de la jornada. Lo gobierna el agregado VehicleRoute: cada
 * parada que se completa (o falla) mueve la ruta de Planned a InProgress, y a Completed cuando ya no
 * queda ninguna parada pendiente.
 */
enum RouteStatus: string
{
    /** Armada pero todavía sin ninguna parada atendida. */
    case Planned = 'PLANNED';

    /** En curso: al menos una parada atendida, con pendientes todavía. */
    case InProgress = 'IN_PROGRESS';

    /** Terminada: ya no quedan paradas pendientes (todas entregadas o fallidas). */
    case Completed = 'COMPLETED';

    /**
     * Suspendida por un incidente MAYOR del camión (accidente/avería grave): la ruta se detiene y sus
     * paradas pendientes se reprograman para el día siguiente. No vuelve a avanzar en esta jornada.
     */
    case Suspended = 'SUSPENDED';
}

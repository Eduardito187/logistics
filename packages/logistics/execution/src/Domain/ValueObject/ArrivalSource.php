<?php

declare(strict_types=1);

namespace Logistics\Execution\Domain\ValueObject;

/**
 * Origen del aviso de llegada a una parada: la geocerca de la app lo detectó sola, o el chofer lo marcó a
 * mano. Es dato de auditoría (KPIs de tiempo en parada): no cambia la regla de "la primera llegada gana".
 */
enum ArrivalSource: string
{
    /** La geocerca de la app detectó que el camión entró al radio de la parada. */
    case Geofence = 'geofence';

    /** El chofer tocó "Llegué" en la app. */
    case Manual = 'manual';
}

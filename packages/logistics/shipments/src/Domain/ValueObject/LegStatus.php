<?php

declare(strict_types=1);

namespace Logistics\Shipments\Domain\ValueObject;

use Logistics\Shared\Domain\Enum\CaseInsensitiveEnum;

/**
 * Estado de un tramo del envío a lo largo de su recorrido entre dos puntos (origen → destino del tramo).
 *
 * El avance del tramo (quién lo marca en tránsito o cerrado) es de olas posteriores de ejecución; acá
 * solo se define el vocabulario del estado, parte del cimiento del modelo de tramos.
 */
enum LegStatus: string
{
    use CaseInsensitiveEnum;

    /** Todavía no salió: el tramo está planteado pero no arrancó. */
    case Pending = 'pending';

    /** En camino entre el origen y el destino del tramo. */
    case InTransit = 'in_transit';

    /** Terminado: la carga llegó al destino del tramo. */
    case Closed = 'closed';

    public function isClosed(): bool
    {
        return $this === self::Closed;
    }
}

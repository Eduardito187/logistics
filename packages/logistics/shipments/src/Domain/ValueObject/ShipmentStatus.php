<?php

declare(strict_types=1);

namespace Logistics\Shipments\Domain\ValueObject;

/**
 * Estado del envío a lo largo de su ciclo de vida. Este contexto solo produce los primeros estados
 * (recepción y planificación); la ejecución real (carga, ruta, entrega) la gobiernan otros contextos.
 */
enum ShipmentStatus: string
{
    /** Recién ingresado y normalizado; todavía sin plan de reparto. */
    case Received = 'RECEIVED';

    /** Asignado a un camión y una secuencia por el contexto de planificación. */
    case Planned = 'PLANNED';

    /** En ruta / en preparación o carga. */
    case InExecution = 'IN_EXECUTION';

    /** Entregado al cliente con prueba de entrega. */
    case Delivered = 'DELIVERED';

    /** Intento de entrega fallido (ausente, rechazo, dirección incorrecta…). */
    case Failed = 'FAILED';

    /** Anulado antes de completarse. */
    case Cancelled = 'CANCELLED';
}

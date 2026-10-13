<?php

declare(strict_types=1);

namespace Logistics\Execution\Domain\ValueObject;

/**
 * Estado de la entrega a lo largo de su ejecución en terreno. Las transiciones las gobierna el
 * agregado Delivery: cada escaneo válido mueve (o confirma) el estado.
 */
enum DeliveryStatus: string
{
    /** Creada, todavía sin preparar. */
    case Pending = 'PENDING';

    /** Al menos un bulto preparado (EAN → etiqueta → carril). */
    case Prepared = 'PREPARED';

    /** Cargada al vehículo. */
    case Loaded = 'LOADED';

    /** En ruta, eventualmente con pasos por nodos. */
    case InTransit = 'IN_TRANSIT';

    /** Entregada al cliente con prueba de entrega. */
    case Delivered = 'DELIVERED';

    /** Intento de entrega fallido (ausente, rechazo, dirección incorrecta…). */
    case Failed = 'FAILED';
}

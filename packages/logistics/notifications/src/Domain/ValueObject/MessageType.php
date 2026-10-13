<?php

declare(strict_types=1);

namespace Logistics\Notifications\Domain\ValueObject;

/**
 * Tipo de mensaje que origina una notificación. Cada tipo puede tener su propia cascada de canales
 * (ver NotificationRulesProvider): por ejemplo, la confirmación de ubicación es el caso que puede
 * escalar hasta SMS como respaldo crítico.
 */
enum MessageType: string
{
    /** El envío salió en ruta hacia el cliente. */
    case EnRoute = 'EN_ROUTE';

    /** El envío está por llegar (ventana corta). */
    case Arriving = 'ARRIVING';

    /** El envío fue entregado. */
    case Delivered = 'DELIVERED';

    /** El intento de entrega falló (ausente, rechazo, dirección incorrecta…). */
    case Failed = 'FAILED';

    /** Se pide al cliente confirmar su ubicación (dirección de baja confianza). */
    case ConfirmLocation = 'CONFIRM_LOCATION';
}

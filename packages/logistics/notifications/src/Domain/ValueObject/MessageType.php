<?php

declare(strict_types=1);

namespace Logistics\Notifications\Domain\ValueObject;

/**
 * Tipo de mensaje que origina una notificación al cliente. Cada tipo puede tener su propia cascada de
 * canales (ver NotificationRulesProvider) y su propia plantilla por canal (MessageTemplate).
 *
 * El catálogo cubre el ciclo de vida del pedido de punta a punta: desde la compra exitosa hasta la
 * entrega o el recojo en tienda, pasando por los hitos logísticos (centro de distribución,
 * preparación, carga, ruta) y las excepciones (fallida, reprogramado, requiere firma).
 */
enum MessageType: string
{
    /** La compra se registró y cobró correctamente (origen del pedido). */
    case PurchaseConfirmed = 'PURCHASE_CONFIRMED';

    /** El pedido fue recibido y normalizado por la plataforma logística. */
    case OrderReceived = 'ORDER_RECEIVED';

    /** El pedido llegó al centro de distribución (CD). */
    case AtDistributionCenter = 'AT_DISTRIBUTION_CENTER';

    /** El pedido está en preparación/picking en el nodo. */
    case InPreparation = 'IN_PREPARATION';

    /** El pedido fue cargado al vehículo. */
    case Loaded = 'LOADED';

    /** El envío salió en ruta hacia el cliente. */
    case EnRoute = 'EN_ROUTE';

    /** El envío está por llegar / llegando (ventana corta, con paradas restantes). */
    case Arriving = 'ARRIVING';

    /** El envío fue entregado. */
    case Delivered = 'DELIVERED';

    /** Se pide al cliente su firma/confirmación para completar la entrega. */
    case RequiresSignature = 'REQUIRES_SIGNATURE';

    /** El intento de entrega falló (ausente, rechazo, dirección incorrecta…). */
    case Failed = 'FAILED';

    /** La entrega fue reprogramada para otra fecha/ventana. */
    case Rescheduled = 'RESCHEDULED';

    /** El envío sigue en ruta pero llegará con retraso (p. ej. por un incidente leve del camión). */
    case DelayedEnRoute = 'DELAYED_EN_ROUTE';

    /** Aviso interno al SUPERVISOR por un incidente de camión (avería/accidente) en ruta. */
    case SupervisorIncident = 'SUPERVISOR_INCIDENT';

    /** El pedido está listo para recojo en tienda/punto. */
    case ReadyForPickup = 'READY_FOR_PICKUP';

    /** El pedido fue recogido por el cliente en tienda/punto. */
    case PickedUp = 'PICKED_UP';

    /** Se pide al cliente confirmar su ubicación (dirección de baja confianza). */
    case ConfirmLocation = 'CONFIRM_LOCATION';
}

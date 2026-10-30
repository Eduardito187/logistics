<?php

declare(strict_types=1);

namespace Logistics\Notifications\Infrastructure\Channel;

use Logistics\Notifications\Domain\Contract\MessageTemplateRepository;
use Logistics\Notifications\Domain\Contract\NotificationVariableProvider;
use Logistics\Notifications\Domain\Model\Notification;
use Logistics\Notifications\Domain\ValueObject\Channel;
use Logistics\Notifications\Domain\ValueObject\MessageType;
use Logistics\Notifications\Domain\ValueObject\RenderedMessage;

/**
 * Resuelve el contenido a enviar de una notificación: busca la plantilla del (tipo, canal, locale) y la
 * renderiza con las variables del envío. Si no hay plantilla definida, cae a un texto por defecto por
 * tipo de mensaje (en español, para el cliente), así el canal siempre tiene algo que enviar.
 *
 * Las variables de interpolación ({{order}}, {{customer}}, {{address}}, {{eta}}, {{stops}}, …) las arma
 * el NotificationVariableProvider desde los datos reales del envío y del tracking; aquí solo se suman
 * los alias que ya conoce el agregado (id del envío, topic FCM) como base, dejando que el proveedor
 * los sobrescriba con valores reales (p. ej. {{order}} = referencia humana del pedido).
 *
 * Es un servicio de Infrastructure que usa el dispatcher del outbox; no vive en el dominio porque
 * depende del repositorio de plantillas y del proveedor de variables.
 */
final readonly class NotificationContentRenderer
{
    public function __construct(
        private MessageTemplateRepository $templates,
        private NotificationVariableProvider $variables,
        private string $defaultLocale,
    ) {}

    public function render(Notification $notification, Channel $channel): RenderedMessage
    {
        $variables = [
            'shipment_id' => $notification->shipmentId(),
            // Alias legible base; el proveedor lo sobrescribe con la referencia humana del pedido.
            'order' => $notification->shipmentId(),
            'tracking_topic' => $notification->fcmTopic()->value,
        ];

        // Variables reales del envío/tracking: sobrescriben los alias base con datos vivos. Si no hay
        // nada que resolver, el proveedor devuelve un mapa vacío y se mantienen los alias base.
        $variables = array_merge(
            $variables,
            $this->variables->variablesFor($notification->shipmentId(), $notification->messageType()),
        );

        $template = $this->templates->findFor($notification->messageType(), $channel, $this->defaultLocale);
        if ($template !== null) {
            return $template->render($variables);
        }

        return $this->defaultFor($notification->messageType());
    }

    /**
     * Texto por defecto por tipo de mensaje cuando no hay plantilla cargada (P1-NOT-08).
     */
    private function defaultFor(MessageType $messageType): RenderedMessage
    {
        return match ($messageType) {
            MessageType::PurchaseConfirmed => RenderedMessage::of('¡Compra confirmada!', 'Recibimos tu compra y ya la estamos preparando. ¡Gracias por elegir Dismac!'),
            MessageType::OrderReceived => RenderedMessage::of('Pedido recibido', 'Tu pedido fue recibido y está en proceso. Te avisaremos en cada paso.'),
            MessageType::AtDistributionCenter => RenderedMessage::of('Tu pedido llegó al centro de distribución', 'Tu pedido ya está en nuestro centro de distribución, listo para prepararse.'),
            MessageType::InPreparation => RenderedMessage::of('Estamos preparando tu pedido', 'Tu pedido está en preparación. Pronto saldrá hacia tu dirección.'),
            MessageType::Loaded => RenderedMessage::of('Tu pedido fue cargado', 'Tu pedido ya está cargado en el vehículo de reparto.'),
            MessageType::EnRoute => RenderedMessage::of('Tu pedido va en camino', 'Tu pedido salió en ruta hacia tu dirección.'),
            MessageType::DelayedEnRoute => RenderedMessage::of('Tu pedido llegará con un retraso', 'Tu pedido sigue en ruta, pero llegará con un pequeño retraso. Gracias por tu paciencia.'),
            // Aviso interno al supervisor (no al cliente): incidente de un vehículo en ruta.
            MessageType::SupervisorIncident => RenderedMessage::of('Incidente de vehículo en ruta', 'Se reportó un incidente de un vehículo en ruta. Revisa la operación para reasignar o reprogramar las entregas afectadas.'),
            MessageType::Arriving => RenderedMessage::of('Tu pedido está por llegar', 'El repartidor está por llegar con tu pedido.'),
            MessageType::Delivered => RenderedMessage::of('Pedido entregado', 'Tu pedido fue entregado. ¡Gracias por tu compra!'),
            MessageType::RequiresSignature => RenderedMessage::of('Tu pedido requiere tu firma', 'Para completar la entrega necesitamos tu firma o confirmación al recibir el pedido.'),
            MessageType::Failed => RenderedMessage::of('No pudimos entregar tu pedido', 'Intentamos entregar tu pedido y no fue posible. Te contactaremos para reprogramar.'),
            MessageType::Rescheduled => RenderedMessage::of('Tu entrega fue reprogramada', 'Reprogramamos la entrega de tu pedido. Te avisaremos la nueva fecha y ventana.'),
            MessageType::ReadyForPickup => RenderedMessage::of('Tu pedido está listo para recojo', 'Tu pedido ya está listo para que lo recojas en la tienda seleccionada.'),
            MessageType::PickedUp => RenderedMessage::of('Pedido recogido', 'Confirmamos que recogiste tu pedido. ¡Gracias por tu compra!'),
            MessageType::ConfirmLocation => RenderedMessage::of('Confirma tu ubicación', 'Necesitamos que confirmes tu dirección de entrega para completar tu pedido.'),
            // Comercio (EC-F0-11): hitos del checkout y del pago.
            MessageType::OrderPlaced => RenderedMessage::of('Recibimos tu pedido', 'Recibimos tu pedido y estamos esperando la confirmación de tu pago. Te avisaremos apenas se acredite.'),
            MessageType::PaymentConfirmed => RenderedMessage::of('¡Pago confirmado!', 'Confirmamos el pago de tu pedido. ¡Gracias por elegir Dismac! Ya lo estamos preparando.'),
            MessageType::PaymentFailed => RenderedMessage::of('No pudimos procesar tu pago', 'No pudimos procesar el pago de tu pedido. Por favor intenta nuevamente o usa otro medio de pago.'),
        };
    }
}

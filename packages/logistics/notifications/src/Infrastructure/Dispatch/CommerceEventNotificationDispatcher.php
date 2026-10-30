<?php

declare(strict_types=1);

namespace Logistics\Notifications\Infrastructure\Dispatch;

use Logistics\Notifications\Application\Command\QueueNotification\QueueNotification;
use Logistics\Notifications\Domain\Contract\NotificationRepository;
use Logistics\Notifications\Domain\ValueObject\MessageType;
use Logistics\Outbox\Application\Contract\MessageDispatcher;
use Logistics\Outbox\Domain\OutboxMessage;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Wiring por eventos de COMERCIO (EC-F0-11): cuando el relay del outbox procesa un hecho del checkout o
 * del pago (contextos Orders / Payments), este dispatcher traduce el evento al tipo de mensaje al cliente
 * que le corresponde y encola la notificación con el comando QueueNotification.
 *
 * Estos hitos son PREVIOS al ciclo logístico del envío (todavía no hay shipment), así que la notificación
 * se ancla al id de la ORDEN: ese id hace de topic FCM (el canal primario va por topic, sin datos de
 * contacto) y de clave de idempotencia. Cuando más tarde nazca el envío, los hitos logísticos los atiende
 * el DomainEventNotificationDispatcher con su propio anclaje (shipment_id).
 *
 * Es idempotente: antes de encolar comprueba que no exista ya una notificación de esa orden y tipo, así
 * la reentrega at-least-once del outbox no duplica avisos. La propia QueueNotification graba sus eventos
 * al outbox (RecordDomainEvents), de modo que el envío real sigue siendo asíncrono y transaccional.
 *
 * Consume los eventos por su NOMBRE y PAYLOAD, sin `use` de clases de Orders/Payments: así cada contexto
 * queda extraíble a microservicio. Solo atiende los eventos del mapa; cualquier otro lo ignora, para
 * componerse con los demás reactores del mismo evento (p. ej. el puente payments → orders) sin pisarlos.
 */
final readonly class CommerceEventNotificationDispatcher implements MessageDispatcher
{
    /**
     * Mapa evento de comercio → tipo de mensaje al cliente. Un evento sin entrada no genera notificación.
     *
     * Nota: se notifica sobre `payments.captured` (confirmación del cobro), NO sobre `orders.order_paid`
     * (su consecuencia interna que crea el envío y emite la factura), para no avisar dos veces lo mismo.
     *
     * @var array<string, MessageType>
     */
    private const EVENT_MAP = [
        'orders.order_placed' => MessageType::OrderPlaced,
        'payments.captured' => MessageType::PaymentConfirmed,
        'payments.failed' => MessageType::PaymentFailed,
    ];

    public function __construct(
        private NotificationRepository $notifications,
        private CommandBus $commandBus,
    ) {}

    public function dispatch(OutboxMessage $message): void
    {
        $messageType = self::EVENT_MAP[$message->eventName()] ?? null;
        if ($messageType === null) {
            return;
        }

        $orderId = $this->resolveOrderId($message);
        if ($orderId === null) {
            return;
        }

        // Idempotencia: no re-encolar el mismo hito de la misma orden ante reentregas del outbox.
        if ($this->notifications->existsForShipmentAndType($orderId, $messageType)) {
            return;
        }

        $payload = $message->payload();
        $email = isset($payload['customer_email']) && is_string($payload['customer_email']) ? $payload['customer_email'] : null;
        $phone = isset($payload['customer_phone']) && is_string($payload['customer_phone']) ? $payload['customer_phone'] : null;
        $telegramChatId = isset($payload['telegram_chat_id']) && is_string($payload['telegram_chat_id']) ? $payload['telegram_chat_id'] : null;

        $this->commandBus->dispatch(new QueueNotification(
            $orderId,
            $messageType->value,
            $email,
            $phone,
            $telegramChatId,
        ));
    }

    /**
     * Id de la orden a la que se ancla la notificación. Los eventos de pago lo llevan en el payload
     * (`order_id`); el de checkout (orders.order_placed) es el propio agregado (aggregateId). Devuelve
     * null si no se puede resolver, para ignorar el mensaje sin romper.
     */
    private function resolveOrderId(OutboxMessage $message): ?string
    {
        $payload = $message->payload();
        if (isset($payload['order_id']) && is_string($payload['order_id']) && $payload['order_id'] !== '') {
            return $payload['order_id'];
        }

        $aggregateId = $message->aggregateId();

        return $aggregateId !== '' ? $aggregateId : null;
    }
}

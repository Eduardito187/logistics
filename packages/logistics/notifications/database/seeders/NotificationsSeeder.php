<?php

declare(strict_types=1);

namespace Logistics\Notifications\Database\Seeders;

use Illuminate\Database\Seeder;
use Logistics\Notifications\Domain\ValueObject\Channel;
use Logistics\Notifications\Domain\ValueObject\EscalateCondition;
use Logistics\Notifications\Domain\ValueObject\MessageType;
use Logistics\Notifications\Domain\ValueObject\NotificationStatus;
use Logistics\Notifications\Infrastructure\Persistence\Eloquent\MessageTemplateRecord;
use Logistics\Notifications\Infrastructure\Persistence\Eloquent\MessageTypeRuleRecord;
use Logistics\Notifications\Infrastructure\Persistence\Eloquent\NotificationRecord;
use Logistics\Shared\Domain\ValueObject\Uuid;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentRecord;

/**
 * Datos de demo del contexto notifications: plantillas en español por tipo de mensaje y canal, una
 * regla de cascada personalizada para la confirmación de ubicación (que llega hasta SMS) y un puñado
 * de notificaciones de ejemplo para el log.
 *
 * Es idempotente (upsert por clave): se puede correr varias veces sin duplicar. El integrador lo
 * invoca desde database/seeders/DatabaseSeeder.php.
 */
final class NotificationsSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedTemplates();
        $this->seedRules();
        $this->seedNotifications();
    }

    /**
     * Plantillas de demo por tipo de mensaje y canal (asunto solo relevante para email).
     */
    private function seedTemplates(): void
    {
        // Catálogo de demo del ciclo de vida del pedido: [tipo, asunto, cuerpo]. Contenido en español
        // (los clientes son bolivianos), con placeholders que el renderizador reemplaza con datos del
        // envío: {{order}}/{{shipment_id}}, {{eta}}, {{stops}}, {{customer}}.
        $templates = [
            [MessageType::PurchaseConfirmed, '¡Compra confirmada!', 'Recibimos tu compra del pedido {{order}}. ¡Gracias por elegir Dismac! Ya la estamos preparando.'],
            [MessageType::OrderReceived, 'Pedido recibido', 'Tu pedido {{order}} fue recibido y está en proceso. Te avisaremos en cada paso.'],
            [MessageType::AtDistributionCenter, 'Tu pedido llegó al centro de distribución', 'Tu pedido {{order}} ya está en nuestro centro de distribución, listo para prepararse.'],
            [MessageType::InPreparation, 'Estamos preparando tu pedido', 'Tu pedido {{order}} está en preparación. Pronto saldrá hacia tu dirección.'],
            [MessageType::Loaded, 'Tu pedido fue cargado', 'Tu pedido {{order}} ya está cargado en el vehículo de reparto.'],
            [MessageType::EnRoute, 'Tu pedido va en camino', 'Tu pedido {{order}} salió en ruta hacia tu dirección. Tiempo estimado: {{eta}}.'],
            [MessageType::Arriving, 'Tu pedido está por llegar', 'El repartidor está por llegar con tu pedido {{order}}. Estás a {{stops}} paradas. Llega en {{eta}}.'],
            [MessageType::Delivered, 'Pedido entregado', 'Tu pedido {{order}} fue entregado. ¡Gracias por tu compra!'],
            [MessageType::RequiresSignature, 'Tu pedido requiere tu firma', 'Para completar la entrega del pedido {{order}} necesitamos tu firma o confirmación al recibirlo.'],
            [MessageType::Failed, 'No pudimos entregar tu pedido', 'Intentamos entregar tu pedido {{order}} y no fue posible. Te contactaremos para reprogramar.'],
            [MessageType::Rescheduled, 'Tu entrega fue reprogramada', 'Reprogramamos la entrega de tu pedido {{order}}. Nueva fecha estimada: {{eta}}.'],
            [MessageType::ReadyForPickup, 'Tu pedido está listo para recojo', 'Tu pedido {{order}} ya está listo para que lo recojas en la tienda seleccionada.'],
            [MessageType::PickedUp, 'Pedido recogido', 'Confirmamos que recogiste tu pedido {{order}}. ¡Gracias por tu compra!'],
            [MessageType::ConfirmLocation, 'Confirma tu ubicación', 'Necesitamos que confirmes tu dirección para entregar el pedido {{order}}.'],
            // Comercio (EC-F0-11): hitos del checkout y del pago, previos al ciclo logístico del envío.
            // Se anclan al id de la orden, así que {{order}} resuelve a la referencia del pedido.
            [MessageType::OrderPlaced, 'Recibimos tu pedido', 'Recibimos tu pedido {{order}} y estamos esperando la confirmación de tu pago. Te avisaremos apenas se acredite.'],
            [MessageType::PaymentConfirmed, '¡Pago confirmado!', 'Confirmamos el pago de tu pedido {{order}}. ¡Gracias por elegir Dismac! Ya lo estamos preparando.'],
            [MessageType::PaymentFailed, 'No pudimos procesar tu pago', 'No pudimos procesar el pago de tu pedido {{order}}. Por favor intenta nuevamente o usa otro medio de pago.'],
        ];

        // Todos los canales: push FCM, Telegram, email y SMS.
        $channels = [
            Channel::Fcm,
            Channel::Telegram,
            Channel::Email,
            Channel::Sms,
        ];

        foreach ($templates as [$type, $subject, $body]) {
            foreach ($channels as $channel) {
                MessageTemplateRecord::query()->updateOrCreate(
                    [
                        'message_type' => $type->value,
                        'channel' => $channel->value,
                        'locale' => 'es',
                    ],
                    [
                        'id' => Uuid::generate()->value,
                        // El SMS va sin asunto; email y mensajería usan el asunto como título.
                        'subject' => $channel === Channel::Sms ? '' : $subject,
                        'body' => $body,
                    ],
                );
            }
        }
    }

    /**
     * Regla personalizada: la confirmación de ubicación recorre toda la cascada hasta SMS.
     */
    private function seedRules(): void
    {
        MessageTypeRuleRecord::query()->updateOrCreate(
            ['message_type' => MessageType::ConfirmLocation->value],
            [
                'channels' => [
                    Channel::Fcm->value,
                    Channel::Telegram->value,
                    Channel::Email->value,
                    Channel::Sms->value,
                ],
                'escalate_when' => EscalateCondition::NoOpen->value,
            ],
        );
    }

    /**
     * Notificaciones de ejemplo para poblar el log del panel.
     *
     * Enlaza cada notificación con un ENVÍO real ya sembrado (shipment_id), repartiendo un abanico de
     * tipos de mensaje y estados representativos, para que la columna "Pedido" del panel no quede vacía
     * ni apunte a un envío inexistente. Si todavía no hay envíos reales (p. ej. seeder aislado en test),
     * cae en el abanico por factory con uuids aleatorios.
     */
    private function seedNotifications(): void
    {
        if (NotificationRecord::query()->exists()) {
            return;
        }

        /** @var list<string> $shipmentIds */
        $shipmentIds = ShipmentRecord::query()->orderBy('external_id')->pluck('id')->all();

        if ($shipmentIds === []) {
            NotificationRecord::factory()->count(8)->create();
            NotificationRecord::factory()->count(4)->sent()->create();

            return;
        }

        // Tipo de mensaje + estado a repartir cíclicamente entre los envíos reales, cubriendo el ciclo
        // de vida del pedido: recibido/encolado, en preparación/enviado, en ruta, por llegar, entregado
        // y un fallo.
        $cycle = [
            [MessageType::OrderReceived, NotificationStatus::Queued],
            [MessageType::InPreparation, NotificationStatus::Sent],
            [MessageType::EnRoute, NotificationStatus::Sent],
            [MessageType::Arriving, NotificationStatus::Sent],
            [MessageType::Delivered, NotificationStatus::Sent],
            [MessageType::Failed, NotificationStatus::Failed],
        ];

        foreach ($shipmentIds as $index => $shipmentId) {
            [$type, $status] = $cycle[$index % count($cycle)];

            $factory = NotificationRecord::factory()->state([
                'shipment_id' => $shipmentId,
                'message_type' => $type->value,
            ]);

            if ($status === NotificationStatus::Sent) {
                $factory = $factory->sent();
            } elseif ($status === NotificationStatus::Failed) {
                $factory = $factory->state(['status' => NotificationStatus::Failed->value]);
            }

            $factory->create();
        }
    }
}

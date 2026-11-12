<?php

declare(strict_types=1);

namespace Logistics\SapConnector\Infrastructure\Outbox;

use Illuminate\Contracts\Container\Container;
use Logistics\Outbox\Application\Contract\OutboxObserver;
use Logistics\Outbox\Domain\OutboxMessage;
use Logistics\SapConnector\Application\Notification\RecordSapNotification;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Plugin del outbox (OutboxObserver) que avisa a SAP los hechos de despacho, fire-and-forget.
 *
 * Por cada mensaje que procesa el relay, si su `event_name` está en la lista configurada de «eventos a
 * avisar» registra UNA fila en `sap_notifications` (idempotente por event_id) y encola su envío en la
 * cola `sap`. No es un reactor del despacho: no está en `routes` ni en ningún `fanout`, así que un fallo
 * acá jamás saltea a otro reactor ni cambia el estado del mensaje del outbox.
 *
 * NUNCA lanza: cualquier problema (config, base, cola) se loguea y se traga. El caso de uso se resuelve
 * perezosamente en cada observación para tomar siempre la política vigente (config refrescada).
 */
final readonly class SapNotificationRecorder implements OutboxObserver
{
    public function __construct(
        private Container $container,
        private ?LoggerInterface $logger = null,
    ) {}

    public function observe(OutboxMessage $message): void
    {
        try {
            /** @var RecordSapNotification $record */
            $record = $this->container->make(RecordSapNotification::class);

            $record->record(
                $message->eventId()->value,
                $message->eventName(),
                $message->aggregateId(),
                $message->payload(),
                $message->createdAt(),
            );
        } catch (Throwable $error) {
            $this->logger?->warning('SAP notification could not be recorded; dispatch is unaffected', [
                'event_name' => $message->eventName(),
                'event_id' => $message->eventId()->value,
                'error' => $error->getMessage(),
            ]);
        }
    }
}

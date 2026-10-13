<?php

declare(strict_types=1);

namespace Logistics\Outbox\Infrastructure\Dispatch;

use Logistics\Outbox\Application\Contract\MessageDispatcher;
use Logistics\Outbox\Domain\OutboxMessage;
use Psr\Log\LoggerInterface;

/**
 * Dispatcher por defecto: no envía nada a ningún sistema externo, solo deja rastro en el log.
 *
 * Es el binding inicial del contrato, pensado para desarrollo y pruebas: deja ver el flujo del outbox
 * sin salir de la plataforma. Cada canal real (SAP, FCM, email, SMS, webhooks) reemplaza este binding
 * con su propia implementación, normalmente ruteando por eventName.
 */
final readonly class NullMessageDispatcher implements MessageDispatcher
{
    public function __construct(
        private ?LoggerInterface $logger = null,
    ) {}

    public function dispatch(OutboxMessage $message): void
    {
        $this->logger?->debug('Outbox message discarded by NullMessageDispatcher', [
            'id' => $message->id()->toString(),
            'event_id' => $message->eventId()->value,
            'event_name' => $message->eventName(),
            'aggregate_type' => $message->aggregateType(),
            'aggregate_id' => $message->aggregateId(),
        ]);
    }
}

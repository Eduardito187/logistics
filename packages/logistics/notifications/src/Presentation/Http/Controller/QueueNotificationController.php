<?php

declare(strict_types=1);

namespace Logistics\Notifications\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\Notifications\Application\Command\QueueNotification\QueueNotification;
use Logistics\Notifications\Presentation\Http\Request\QueueNotificationRequest;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Encola una notificación de un envío (puerta integrations).
 *
 * La presentación solo habla con el CommandBus; el envío real sale por outbox, nunca dentro del
 * request. Por eso responde 202 Accepted: la notificación quedó encolada, no entregada.
 */
final readonly class QueueNotificationController
{
    public function __construct(
        private CommandBus $commandBus,
    ) {}

    public function __invoke(QueueNotificationRequest $request): JsonResponse
    {
        /** @var array{shipment_id: string, message_type: string, email?: string|null, phone?: string|null} $data */
        $data = $request->validated();

        $this->commandBus->dispatch(new QueueNotification(
            shipmentId: $data['shipment_id'],
            messageType: $data['message_type'],
            email: $data['email'] ?? null,
            phone: $data['phone'] ?? null,
        ));

        return new JsonResponse([
            'data' => [
                'shipment_id' => $data['shipment_id'],
                'message_type' => $data['message_type'],
                'status' => 'accepted',
            ],
        ], 202);
    }
}

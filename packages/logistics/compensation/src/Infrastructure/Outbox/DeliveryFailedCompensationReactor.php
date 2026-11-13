<?php

declare(strict_types=1);

namespace Logistics\Compensation\Infrastructure\Outbox;

use Logistics\Compensation\Application\Command\OpenCompensationCase\OpenCompensationCase;
use Logistics\Compensation\Domain\Contract\CompensationPolicyProvider;
use Logistics\Compensation\Domain\ValueObject\CompensationScope;
use Logistics\Compensation\Domain\ValueObject\TriggerType;
use Logistics\Outbox\Application\Contract\MessageDispatcher;
use Logistics\Outbox\Domain\OutboxMessage;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Reactor outbox: una entrega fallida (`execution.delivery_failed`) abre un caso de compensación SOLO si el
 * motivo es atribuible a nosotros (DAMAGED / OTHER por política; nunca CUSTOMER_ABSENT / WRONG_ADDRESS /
 * REJECTED). Idempotente por (disparador, envío): una reentrega del outbox no abre dos casos.
 */
final readonly class DeliveryFailedCompensationReactor implements MessageDispatcher
{
    public function __construct(
        private CommandBus $commandBus,
        private CompensationPolicyProvider $policyProvider,
    ) {}

    public function dispatch(OutboxMessage $message): void
    {
        if ($message->eventName() !== 'execution.delivery_failed') {
            return;
        }

        $payload = $message->payload();
        $shipmentId = $this->stringOrNull($payload['shipment_id'] ?? $message->aggregateId());
        $reason = $this->stringOrNull($payload['reason'] ?? ($payload['failure_reason'] ?? null));
        if ($shipmentId === null || $reason === null) {
            return;
        }

        if (!$this->policyProvider->current()->isFailureCompensable($reason)) {
            return;
        }

        $this->commandBus->dispatch(new OpenCompensationCase(
            trigger: TriggerType::DeliveryFailed->value,
            scope: CompensationScope::Shipment->value,
            shipmentId: $shipmentId,
            orderReference: $this->stringOrNull($payload['order_reference'] ?? null),
            sourceEventId: $message->eventId()->value,
            idempotencyKey: TriggerType::DeliveryFailed->value.':'.$shipmentId,
            proposedBy: 'system',
            ownership: $this->stringOrNull($payload['ownership'] ?? null),
        ));
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}

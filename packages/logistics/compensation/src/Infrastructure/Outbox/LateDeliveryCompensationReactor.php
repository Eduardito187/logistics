<?php

declare(strict_types=1);

namespace Logistics\Compensation\Infrastructure\Outbox;

use DateTimeImmutable;
use Exception;
use Logistics\Compensation\Application\Command\OpenCompensationCase\OpenCompensationCase;
use Logistics\Compensation\Application\Contract\PromisedWindowReader;
use Logistics\Compensation\Domain\Contract\CompensationPolicyProvider;
use Logistics\Compensation\Domain\ValueObject\CompensationScope;
use Logistics\Compensation\Domain\ValueObject\TriggerType;
use Logistics\Outbox\Application\Contract\MessageDispatcher;
use Logistics\Outbox\Domain\OutboxMessage;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Domain\Clock\Clock;

/**
 * Reactor outbox: una entrega (`execution.shipment_delivered`) abre un caso LATE_DELIVERY si superó la
 * ventana prometida más la tolerancia de la política. La hora de entrega se toma del momento del evento.
 * Excluye reprogramaciones pedidas por el cliente. Idempotente por (disparador, envío).
 */
final readonly class LateDeliveryCompensationReactor implements MessageDispatcher
{
    public function __construct(
        private CommandBus $commandBus,
        private CompensationPolicyProvider $policyProvider,
        private PromisedWindowReader $promisedWindowReader,
        private Clock $clock,
    ) {}

    public function dispatch(OutboxMessage $message): void
    {
        if ($message->eventName() !== 'execution.shipment_delivered') {
            return;
        }

        $payload = $message->payload();
        $shipmentId = $this->stringOrNull($payload['shipment_id'] ?? $message->aggregateId());
        if ($shipmentId === null) {
            return;
        }
        if ($this->promisedWindowReader->wasRescheduledByCustomer($shipmentId)) {
            return;
        }

        $promisedEnd = $this->promisedWindowReader->promisedEndFor($shipmentId);
        if ($promisedEnd === null) {
            return;
        }

        $tolerance = $this->policyProvider->current()->lateToleranceMinutes;
        $deadline = $promisedEnd->modify("+{$tolerance} minutes");
        $deliveredAt = $this->deliveredAtFrom($payload['occurred_at'] ?? null);
        if ($deliveredAt <= $deadline) {
            return;
        }

        $this->commandBus->dispatch(new OpenCompensationCase(
            trigger: TriggerType::LateDelivery->value,
            scope: CompensationScope::Shipment->value,
            shipmentId: $shipmentId,
            orderReference: $this->stringOrNull($payload['order_reference'] ?? null),
            sourceEventId: $message->eventId()->value,
            idempotencyKey: TriggerType::LateDelivery->value.':'.$shipmentId,
            proposedBy: 'system',
        ));
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Hora de entrega: se toma del momento del evento (`occurred_at`, DATE_ATOM). Si no viaja o es
     * inválida, cae al reloj (momento del despacho), nunca falla.
     */
    private function deliveredAtFrom(mixed $value): DateTimeImmutable
    {
        if (is_string($value) && $value !== '') {
            try {
                return new DateTimeImmutable($value);
            } catch (Exception) {
                // Formato inesperado: cae al reloj.
            }
        }

        return $this->clock->now();
    }
}

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
 * Reactor outbox: una calificación de entrega BAJA abre un caso LOW_RATING (siempre PROPUESTO, aprobación
 * nivel 1 — nunca auto-emitido). Escucha `tracking.satisfaction_survey_recorded` (módulo 1 de Calificación)
 * y considera baja una nota ≤ umbral de la política (default 3). Idempotente por (disparador, envío).
 *
 * Nota de integración: el roadmap nombra el evento `tracking.low_rating_received`; hoy el módulo 1 emite
 * `tracking.satisfaction_survey_recorded` con la nota en la carga, así que el reactor filtra por el umbral
 * aquí (evita depender de un evento que aún no existe). Si luego se agrega el evento dedicado, basta sumar
 * su ruteo al outbox; este reactor no cambia.
 */
final readonly class LowRatingCompensationReactor implements MessageDispatcher
{
    public function __construct(
        private CommandBus $commandBus,
        private CompensationPolicyProvider $policyProvider,
    ) {}

    public function dispatch(OutboxMessage $message): void
    {
        if ($message->eventName() !== 'tracking.satisfaction_survey_recorded') {
            return;
        }

        $payload = $message->payload();
        $shipmentId = $this->stringOrNull($payload['shipment_id'] ?? null);
        $rating = $payload['rating'] ?? null;
        if ($shipmentId === null || !is_int($rating)) {
            return;
        }

        if ($rating > $this->policyProvider->current()->lowRatingThreshold) {
            return;
        }

        $this->commandBus->dispatch(new OpenCompensationCase(
            trigger: TriggerType::LowRating->value,
            scope: CompensationScope::Shipment->value,
            shipmentId: $shipmentId,
            orderReference: $this->stringOrNull($payload['order_reference'] ?? null),
            sourceEventId: $message->eventId()->value,
            idempotencyKey: TriggerType::LowRating->value.':'.$shipmentId,
            proposedBy: 'system',
        ));
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}

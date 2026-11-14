<?php

declare(strict_types=1);

namespace Logistics\Compensation\Infrastructure\Outbox;

use Logistics\Compensation\Application\Command\OpenCompensationCase\OpenCompensationCase;
use Logistics\Compensation\Domain\ValueObject\CompensationScope;
use Logistics\Compensation\Domain\ValueObject\TriggerType;
use Logistics\Outbox\Application\Contract\MessageDispatcher;
use Logistics\Outbox\Domain\OutboxMessage;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Reactor outbox: una evidencia de DAÑO VALIDADA como `ok` (`execution.delivery_evidence_validated`,
 * kind `damage`) abre un caso DAMAGED_PRODUCT. Como la evidencia ya está validada y el disparador es
 * auto-aprobable, el alta encadena la aprobación y emite el vale si el monto cae dentro del tope de
 * auto-aprobación. Idempotente por (disparador, envío).
 *
 * Validaciones `review`/`mismatch` NO generan caso automático (quedan para revisión manual).
 */
final readonly class DamageEvidenceCompensationReactor implements MessageDispatcher
{
    public function __construct(
        private CommandBus $commandBus,
    ) {}

    public function dispatch(OutboxMessage $message): void
    {
        if ($message->eventName() !== 'execution.delivery_evidence_validated') {
            return;
        }

        $payload = $message->payload();
        $kind = strtolower((string) ($payload['kind'] ?? ''));
        $status = strtolower((string) ($payload['validation_status'] ?? ''));
        $shipmentId = $this->stringOrNull($payload['shipment_id'] ?? null);

        if ($kind !== 'damage' || $status !== 'ok' || $shipmentId === null) {
            return;
        }

        $this->commandBus->dispatch(new OpenCompensationCase(
            trigger: TriggerType::DamagedProduct->value,
            scope: CompensationScope::Shipment->value,
            shipmentId: $shipmentId,
            orderReference: $this->stringOrNull($payload['order_reference'] ?? null),
            sourceEventId: $message->eventId()->value,
            idempotencyKey: TriggerType::DamagedProduct->value.':'.$shipmentId,
            proposedBy: 'system',
        ));
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}

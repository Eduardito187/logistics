<?php

declare(strict_types=1);

use Logistics\Compensation\Application\Command\OpenCompensationCase\OpenCompensationCase;
use Logistics\Compensation\Domain\ValueObject\TriggerType;
use Logistics\Compensation\Infrastructure\Outbox\DamageEvidenceCompensationReactor;
use Logistics\Outbox\Domain\OutboxMessage;
use Logistics\Outbox\Domain\ValueObject\OutboxMessageId;
use Logistics\Outbox\Domain\ValueObject\OutboxStatus;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Domain\ValueObject\Uuid;

// Reactor outbox: una evidencia de DAÑO validada `ok` abre un caso DAMAGED_PRODUCT.

function damageReactorBus(): CommandBus
{
    return new class implements CommandBus
    {
        /** @var list<Command> */
        public array $dispatched = [];

        public function dispatch(Command $command): void
        {
            $this->dispatched[] = $command;
        }
    };
}

function damageMessage(string $eventName, array $payload): OutboxMessage
{
    return OutboxMessage::restore(
        OutboxMessageId::generate(),
        Uuid::generate(),
        'execution.shipment',
        'ship-1',
        $eventName,
        $payload,
        OutboxStatus::Pending,
        0,
        new DateTimeImmutable,
        new DateTimeImmutable,
        null,
        null,
    );
}

it('ignora un evento que no es delivery_evidence_validated', function (): void {
    $bus = damageReactorBus();
    (new DamageEvidenceCompensationReactor($bus))->dispatch(damageMessage('execution.other', []));

    expect($bus->dispatched)->toBe([]);
});

it('abre un caso de daño cuando la evidencia es damage y status ok', function (): void {
    $bus = damageReactorBus();
    (new DamageEvidenceCompensationReactor($bus))->dispatch(damageMessage('execution.delivery_evidence_validated', [
        'kind' => 'DAMAGE',
        'validation_status' => 'OK',
        'shipment_id' => 'ship-1',
        'order_reference' => 'ORD-1',
    ]));

    expect($bus->dispatched)->toHaveCount(1);
    $command = $bus->dispatched[0];
    expect($command)->toBeInstanceOf(OpenCompensationCase::class)
        ->and($command->trigger)->toBe(TriggerType::DamagedProduct->value)
        ->and($command->shipmentId)->toBe('ship-1')
        ->and($command->orderReference)->toBe('ORD-1');
});

it('no abre caso si la validación no es ok', function (): void {
    $bus = damageReactorBus();
    (new DamageEvidenceCompensationReactor($bus))->dispatch(damageMessage('execution.delivery_evidence_validated', [
        'kind' => 'damage',
        'validation_status' => 'review',
        'shipment_id' => 'ship-1',
    ]));

    expect($bus->dispatched)->toBe([]);
});

it('no abre caso si el tipo de evidencia no es damage', function (): void {
    $bus = damageReactorBus();
    (new DamageEvidenceCompensationReactor($bus))->dispatch(damageMessage('execution.delivery_evidence_validated', [
        'kind' => 'signature',
        'validation_status' => 'ok',
        'shipment_id' => 'ship-1',
    ]));

    expect($bus->dispatched)->toBe([]);
});

it('no abre caso si falta el shipment_id', function (): void {
    $bus = damageReactorBus();
    (new DamageEvidenceCompensationReactor($bus))->dispatch(damageMessage('execution.delivery_evidence_validated', [
        'kind' => 'damage',
        'validation_status' => 'ok',
    ]));

    expect($bus->dispatched)->toBe([]);
});

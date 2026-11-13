<?php

declare(strict_types=1);

use Logistics\Compensation\Application\Command\OpenCompensationCase\OpenCompensationCase;
use Logistics\Compensation\Domain\Contract\CompensationPolicyProvider;
use Logistics\Compensation\Domain\ValueObject\CompensationPolicy;
use Logistics\Compensation\Domain\ValueObject\TriggerType;
use Logistics\Compensation\Infrastructure\Outbox\DeliveryFailedCompensationReactor;
use Logistics\Outbox\Domain\OutboxMessage;
use Logistics\Outbox\Domain\ValueObject\OutboxMessageId;
use Logistics\Outbox\Domain\ValueObject\OutboxStatus;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Domain\ValueObject\Uuid;

// Reactor outbox: una entrega fallida abre caso solo si el motivo es atribuible a nosotros (por política).

function failedReactorBus(): CommandBus
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

function failedReactorPolicy(): CompensationPolicyProvider
{
    $policy = CompensationPolicy::fromArray(['compensable_failure_reasons' => ['DAMAGED', 'OTHER']]);

    return new class($policy) implements CompensationPolicyProvider
    {
        public function __construct(private CompensationPolicy $policy) {}

        public function current(): CompensationPolicy
        {
            return $this->policy;
        }

        public function version(): int
        {
            return 1;
        }
    };
}

function failedMessage(array $payload): OutboxMessage
{
    return OutboxMessage::restore(
        OutboxMessageId::generate(),
        Uuid::generate(),
        'execution.shipment',
        'ship-1',
        'execution.delivery_failed',
        $payload,
        OutboxStatus::Pending,
        0,
        new DateTimeImmutable,
        new DateTimeImmutable,
        null,
        null,
    );
}

it('ignora un evento que no es delivery_failed', function (): void {
    $bus = failedReactorBus();
    $reactor = new DeliveryFailedCompensationReactor($bus, failedReactorPolicy());
    $reactor->dispatch(OutboxMessage::restore(
        OutboxMessageId::generate(),
        Uuid::generate(),
        'execution.shipment',
        'ship-1',
        'execution.delivered',
        ['shipment_id' => 'ship-1', 'reason' => 'DAMAGED'],
        OutboxStatus::Pending,
        0,
        new DateTimeImmutable,
        new DateTimeImmutable,
        null,
        null,
    ));

    expect($bus->dispatched)->toBe([]);
});

it('abre un caso cuando el motivo es compensable', function (): void {
    $bus = failedReactorBus();
    $reactor = new DeliveryFailedCompensationReactor($bus, failedReactorPolicy());
    $reactor->dispatch(failedMessage([
        'shipment_id' => 'ship-1',
        'reason' => 'DAMAGED',
        'order_reference' => 'ORD-1',
        'ownership' => 'OWNED',
    ]));

    expect($bus->dispatched)->toHaveCount(1);
    $command = $bus->dispatched[0];
    expect($command)->toBeInstanceOf(OpenCompensationCase::class)
        ->and($command->trigger)->toBe(TriggerType::DeliveryFailed->value)
        ->and($command->ownership)->toBe('OWNED');
});

it('acepta el motivo bajo la clave failure_reason', function (): void {
    $bus = failedReactorBus();
    $reactor = new DeliveryFailedCompensationReactor($bus, failedReactorPolicy());
    $reactor->dispatch(failedMessage(['shipment_id' => 'ship-1', 'failure_reason' => 'OTHER']));

    expect($bus->dispatched)->toHaveCount(1);
});

it('no abre caso cuando el motivo no es atribuible a nosotros', function (): void {
    $bus = failedReactorBus();
    $reactor = new DeliveryFailedCompensationReactor($bus, failedReactorPolicy());
    $reactor->dispatch(failedMessage(['shipment_id' => 'ship-1', 'reason' => 'CUSTOMER_ABSENT']));

    expect($bus->dispatched)->toBe([]);
});

it('no abre caso si falta shipment_id o motivo', function (): void {
    $bus = failedReactorBus();
    $reactor = new DeliveryFailedCompensationReactor($bus, failedReactorPolicy());
    $reactor->dispatch(failedMessage(['reason' => 'DAMAGED', 'shipment_id' => '']));
    $reactor->dispatch(failedMessage(['shipment_id' => 'ship-1']));

    expect($bus->dispatched)->toBe([]);
});

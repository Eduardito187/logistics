<?php

declare(strict_types=1);

use Logistics\Compensation\Application\Command\OpenCompensationCase\OpenCompensationCase;
use Logistics\Compensation\Application\Contract\PromisedWindowReader;
use Logistics\Compensation\Domain\Contract\CompensationPolicyProvider;
use Logistics\Compensation\Domain\ValueObject\CompensationPolicy;
use Logistics\Compensation\Domain\ValueObject\TriggerType;
use Logistics\Compensation\Infrastructure\Outbox\LateDeliveryCompensationReactor;
use Logistics\Outbox\Domain\OutboxMessage;
use Logistics\Outbox\Domain\ValueObject\OutboxMessageId;
use Logistics\Outbox\Domain\ValueObject\OutboxStatus;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Domain\Clock\Clock;
use Logistics\Shared\Domain\ValueObject\Uuid;

// Reactor outbox: una entrega tardía (fuera de la ventana + tolerancia) abre un caso LATE_DELIVERY.

/**
 * Bus en memoria: guarda los comandos despachados para afirmarlos.
 */
function lateReactorBus(): CommandBus
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

function lateReactorPolicy(int $toleranceMinutes = 60): CompensationPolicyProvider
{
    $policy = CompensationPolicy::fromArray(['late_tolerance_minutes' => $toleranceMinutes]);

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

function lateReactorWindow(?DateTimeImmutable $promisedEnd, bool $rescheduled = false): PromisedWindowReader
{
    return new class($promisedEnd, $rescheduled) implements PromisedWindowReader
    {
        public function __construct(
            private ?DateTimeImmutable $promisedEnd,
            private bool $rescheduled,
        ) {}

        public function promisedEndFor(string $shipmentId): ?DateTimeImmutable
        {
            return $this->promisedEnd;
        }

        public function wasRescheduledByCustomer(string $shipmentId): bool
        {
            return $this->rescheduled;
        }
    };
}

function lateReactorClock(DateTimeImmutable $now): Clock
{
    return new class($now) implements Clock
    {
        public function __construct(private DateTimeImmutable $now) {}

        public function now(): DateTimeImmutable
        {
            return $this->now;
        }
    };
}

function lateMessage(string $eventName, array $payload, string $aggregateId = 'ship-1'): OutboxMessage
{
    return OutboxMessage::restore(
        OutboxMessageId::generate(),
        Uuid::generate(),
        'execution.shipment',
        $aggregateId,
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

it('ignora un evento que no es shipment_delivered', function (): void {
    $bus = lateReactorBus();
    $reactor = new LateDeliveryCompensationReactor(
        $bus,
        lateReactorPolicy(),
        lateReactorWindow(new DateTimeImmutable('2026-11-10 10:00')),
        lateReactorClock(new DateTimeImmutable('2026-11-10 10:00')),
    );

    $reactor->dispatch(lateMessage('execution.something_else', ['shipment_id' => 'ship-1']));

    expect($bus->dispatched)->toBe([]);
});

it('abre un caso cuando la entrega supera la ventana más la tolerancia', function (): void {
    $bus = lateReactorBus();
    $reactor = new LateDeliveryCompensationReactor(
        $bus,
        lateReactorPolicy(60),
        lateReactorWindow(new DateTimeImmutable('2026-11-10 18:00')),
        lateReactorClock(new DateTimeImmutable('2026-11-10 20:00')),
    );

    // Entregado 20:30, ventana 18:00 + 60 min = deadline 19:00 → tardío.
    $reactor->dispatch(lateMessage('execution.shipment_delivered', [
        'shipment_id' => 'ship-1',
        'order_reference' => 'ORD-1',
        'occurred_at' => '2026-11-10T20:30:00+00:00',
    ]));

    expect($bus->dispatched)->toHaveCount(1);
    $command = $bus->dispatched[0];
    expect($command)->toBeInstanceOf(OpenCompensationCase::class)
        ->and($command->trigger)->toBe(TriggerType::LateDelivery->value)
        ->and($command->shipmentId)->toBe('ship-1')
        ->and($command->orderReference)->toBe('ORD-1');
});

it('no abre caso si la entrega cae dentro del plazo', function (): void {
    $bus = lateReactorBus();
    $reactor = new LateDeliveryCompensationReactor(
        $bus,
        lateReactorPolicy(60),
        lateReactorWindow(new DateTimeImmutable('2026-11-10 18:00')),
        lateReactorClock(new DateTimeImmutable('2026-11-10 18:30')),
    );

    $reactor->dispatch(lateMessage('execution.shipment_delivered', [
        'shipment_id' => 'ship-1',
        'occurred_at' => '2026-11-10T18:30:00+00:00',
    ]));

    expect($bus->dispatched)->toBe([]);
});

it('no abre caso si fue reprogramado por el cliente', function (): void {
    $bus = lateReactorBus();
    $reactor = new LateDeliveryCompensationReactor(
        $bus,
        lateReactorPolicy(60),
        lateReactorWindow(new DateTimeImmutable('2026-11-10 18:00'), rescheduled: true),
        lateReactorClock(new DateTimeImmutable('2026-11-10 23:00')),
    );

    $reactor->dispatch(lateMessage('execution.shipment_delivered', [
        'shipment_id' => 'ship-1',
        'occurred_at' => '2026-11-10T23:00:00+00:00',
    ]));

    expect($bus->dispatched)->toBe([]);
});

it('no abre caso si no hay ventana prometida', function (): void {
    $bus = lateReactorBus();
    $reactor = new LateDeliveryCompensationReactor(
        $bus,
        lateReactorPolicy(60),
        lateReactorWindow(null),
        lateReactorClock(new DateTimeImmutable('2026-11-10 23:00')),
    );

    $reactor->dispatch(lateMessage('execution.shipment_delivered', ['shipment_id' => 'ship-1']));

    expect($bus->dispatched)->toBe([]);
});

it('cae al reloj si occurred_at es inválido y usa el aggregateId si falta shipment_id', function (): void {
    $bus = lateReactorBus();
    $reactor = new LateDeliveryCompensationReactor(
        $bus,
        lateReactorPolicy(60),
        lateReactorWindow(new DateTimeImmutable('2026-11-10 18:00')),
        lateReactorClock(new DateTimeImmutable('2026-11-10 22:00')),
    );

    // Sin shipment_id en payload (toma el aggregateId) y occurred_at corrupto (cae al reloj → tardío).
    $reactor->dispatch(lateMessage('execution.shipment_delivered', ['occurred_at' => 'no-es-fecha'], 'ship-agg'));

    expect($bus->dispatched)->toHaveCount(1)
        ->and($bus->dispatched[0]->shipmentId)->toBe('ship-agg');
});

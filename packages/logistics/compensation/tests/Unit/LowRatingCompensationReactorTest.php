<?php

declare(strict_types=1);

use Logistics\Compensation\Application\Command\OpenCompensationCase\OpenCompensationCase;
use Logistics\Compensation\Domain\Contract\CompensationPolicyProvider;
use Logistics\Compensation\Domain\ValueObject\CompensationPolicy;
use Logistics\Compensation\Domain\ValueObject\TriggerType;
use Logistics\Compensation\Infrastructure\Outbox\LowRatingCompensationReactor;
use Logistics\Outbox\Domain\OutboxMessage;
use Logistics\Outbox\Domain\ValueObject\OutboxMessageId;
use Logistics\Outbox\Domain\ValueObject\OutboxStatus;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Domain\ValueObject\Uuid;

// Reactor outbox: una calificación baja (≤ umbral) abre un caso LOW_RATING, siempre propuesto.

function lowRatingBus(): CommandBus
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

function lowRatingPolicy(int $threshold = 3): CompensationPolicyProvider
{
    $policy = CompensationPolicy::fromArray(['low_rating_threshold' => $threshold]);

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

function lowRatingMessage(string $eventName, array $payload): OutboxMessage
{
    return OutboxMessage::restore(
        OutboxMessageId::generate(),
        Uuid::generate(),
        'tracking.satisfaction',
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

it('ignora un evento que no es la encuesta de satisfacción', function (): void {
    $bus = lowRatingBus();
    (new LowRatingCompensationReactor($bus, lowRatingPolicy()))
        ->dispatch(lowRatingMessage('tracking.other', ['shipment_id' => 'ship-1', 'rating' => 1]));

    expect($bus->dispatched)->toBe([]);
});

it('abre un caso cuando la nota es menor o igual al umbral', function (): void {
    $bus = lowRatingBus();
    (new LowRatingCompensationReactor($bus, lowRatingPolicy(3)))
        ->dispatch(lowRatingMessage('tracking.satisfaction_survey_recorded', [
            'shipment_id' => 'ship-1',
            'rating' => 2,
            'order_reference' => 'ORD-1',
        ]));

    expect($bus->dispatched)->toHaveCount(1);
    $command = $bus->dispatched[0];
    expect($command)->toBeInstanceOf(OpenCompensationCase::class)
        ->and($command->trigger)->toBe(TriggerType::LowRating->value)
        ->and($command->shipmentId)->toBe('ship-1');
});

it('no abre caso cuando la nota supera el umbral', function (): void {
    $bus = lowRatingBus();
    (new LowRatingCompensationReactor($bus, lowRatingPolicy(3)))
        ->dispatch(lowRatingMessage('tracking.satisfaction_survey_recorded', [
            'shipment_id' => 'ship-1',
            'rating' => 5,
        ]));

    expect($bus->dispatched)->toBe([]);
});

it('no abre caso si falta el shipment_id o la nota no es entera', function (): void {
    $bus = lowRatingBus();
    $reactor = new LowRatingCompensationReactor($bus, lowRatingPolicy(3));
    $reactor->dispatch(lowRatingMessage('tracking.satisfaction_survey_recorded', ['rating' => 1]));
    $reactor->dispatch(lowRatingMessage('tracking.satisfaction_survey_recorded', [
        'shipment_id' => 'ship-1',
        'rating' => '2',
    ]));

    expect($bus->dispatched)->toBe([]);
});

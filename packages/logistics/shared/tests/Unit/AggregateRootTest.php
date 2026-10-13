<?php

declare(strict_types=1);

use Logistics\Shared\Domain\Aggregate\AggregateRoot;
use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Evento de prueba, local al test.
 */
final readonly class SampleThingHappened extends BaseDomainEvent
{
    public function eventName(): string
    {
        return 'shared.sample_thing_happened';
    }

    public function payload(): array
    {
        return ['aggregate_id' => $this->aggregateId];
    }
}

/**
 * Agregado de prueba que graba un evento al actuar.
 */
final class SampleAggregate extends AggregateRoot
{
    public function doSomething(string $id): void
    {
        $this->recordEvent(new SampleThingHappened($id));
    }
}

it('graba eventos y los libera una sola vez', function (): void {
    $aggregate = new SampleAggregate;
    $aggregate->doSomething('abc');
    $aggregate->doSomething('def');

    expect($aggregate->pendingEvents())->toHaveCount(2);

    $released = $aggregate->releaseEvents();

    expect($released)->toHaveCount(2)
        ->and($released[0])->toBeInstanceOf(SampleThingHappened::class)
        ->and($aggregate->releaseEvents())->toBe([]);
});

it('cada evento trae su propio id y su momento', function (): void {
    $event = new SampleThingHappened('abc');

    expect($event->eventId()->value)->not->toBe('')
        ->and($event->eventName())->toBe('shared.sample_thing_happened')
        ->and($event->aggregateId())->toBe('abc')
        ->and($event->payload())->toBe(['aggregate_id' => 'abc']);
});

<?php

declare(strict_types=1);

use Logistics\Capacity\Application\Command\ActivateCapacityWindow\ActivateCapacityWindowCommand;
use Logistics\Capacity\Application\Command\ActivateCapacityWindow\ActivateCapacityWindowHandler;
use Logistics\Capacity\Domain\ActiveHorizon;
use Logistics\Capacity\Domain\Contract\ActiveHorizonRepository;
use Logistics\Capacity\Domain\Contract\CapacityActivationDispatcher;
use Logistics\Capacity\Domain\Exception\CapacityException;
use Logistics\Capacity\Domain\ValueObject\ActiveHorizonId;
use Logistics\Capacity\Domain\ValueObject\SlotDate;
use Logistics\Outbox\Application\RecordDomainEvents;

/**
 * Repositorio de horizontes en memoria: guarda por alcance y devuelve lo guardado.
 */
function horizonRepo(): ActiveHorizonRepository
{
    return new class implements ActiveHorizonRepository
    {
        /** @var array<string, ActiveHorizon> */
        public array $saved = [];

        public function nextIdentity(): ActiveHorizonId
        {
            return ActiveHorizonId::generate();
        }

        public function save(ActiveHorizon $horizon): void
        {
            $this->saved[$horizon->municipalityId() ?? '__global__'] = $horizon;
        }

        public function findFor(?string $municipalityId): ?ActiveHorizon
        {
            return $this->saved[$municipalityId ?? '__global__'] ?? null;
        }
    };
}

/**
 * Dispatcher de activación en memoria: registra cada municipio encolado.
 */
function activationDispatcher(): CapacityActivationDispatcher
{
    return new class implements CapacityActivationDispatcher
    {
        /** @var list<string> */
        public array $dispatched = [];

        public function dispatch(string $municipalityId, SlotDate $from, SlotDate $to, array $weekdays, array $windows): void
        {
            $this->dispatched[] = $municipalityId;
        }
    };
}

function activateWindowCommand(array $municipalityIds): ActivateCapacityWindowCommand
{
    return new ActivateCapacityWindowCommand(
        municipalityIds: $municipalityIds,
        fromDate: '2026-11-02',
        toDate: '2026-11-30',
        weekdays: [1, 2, 3, 4, 5, 6, 7],
        windows: [
            ['time_slot' => '08:00-12:00', 'unit' => 'deliveries', 'quantity' => 40],
            ['time_slot' => '12:00-16:00', 'unit' => 'deliveries', 'quantity' => 30],
        ],
    );
}

it('advances the active horizon and enqueues the job per municipality', function (): void {
    $repo = horizonRepo();
    $dispatcher = activationDispatcher();
    $handler = new ActivateCapacityWindowHandler($repo, new RecordDomainEvents(collectingOutbox(), capacityFixedClock()), $dispatcher);

    $handler->handle(activateWindowCommand([
        'aaaaaaaa-aaaa-7aaa-8aaa-aaaaaaaaaaaa',
        'bbbbbbbb-bbbb-7bbb-8bbb-bbbbbbbbbbbb',
    ]));

    expect($dispatcher->dispatched)->toHaveCount(2)
        ->and($repo->findFor('aaaaaaaa-aaaa-7aaa-8aaa-aaaaaaaaaaaa')?->activeUntil()->toString())->toBe('2026-11-30')
        ->and($repo->findFor('bbbbbbbb-bbbb-7bbb-8bbb-bbbbbbbbbbbb')?->activeUntil()->toString())->toBe('2026-11-30');
});

it('records the activation event to the outbox', function (): void {
    $repo = horizonRepo();
    $outbox = collectingOutbox();
    $handler = new ActivateCapacityWindowHandler($repo, new RecordDomainEvents($outbox, capacityFixedClock()), activationDispatcher());

    $handler->handle(activateWindowCommand(['aaaaaaaa-aaaa-7aaa-8aaa-aaaaaaaaaaaa']));

    expect($outbox->messages)->toHaveCount(1)
        ->and($outbox->messages[0]->eventName())->toBe('capacity.capacity_window_activated');
});

it('rejects an inverted date range', function (): void {
    $handler = new ActivateCapacityWindowHandler(horizonRepo(), new RecordDomainEvents(collectingOutbox(), capacityFixedClock()), activationDispatcher());

    $command = new ActivateCapacityWindowCommand(
        municipalityIds: ['aaaaaaaa-aaaa-7aaa-8aaa-aaaaaaaaaaaa'],
        fromDate: '2026-11-30',
        toDate: '2026-11-02',
        weekdays: [1],
        windows: [['time_slot' => '08:00-12:00', 'unit' => 'deliveries', 'quantity' => 40]],
    );

    expect(fn () => $handler->handle($command))->toThrow(CapacityException::class, 'rango de activación');
});

it('rejects an activation with no municipalities', function (): void {
    $handler = new ActivateCapacityWindowHandler(horizonRepo(), new RecordDomainEvents(collectingOutbox(), capacityFixedClock()), activationDispatcher());

    expect(fn () => $handler->handle(activateWindowCommand([])))->toThrow(CapacityException::class, 'municipio');
});

it('rejects an activation with no windows', function (): void {
    $handler = new ActivateCapacityWindowHandler(horizonRepo(), new RecordDomainEvents(collectingOutbox(), capacityFixedClock()), activationDispatcher());

    $command = new ActivateCapacityWindowCommand(
        municipalityIds: ['aaaaaaaa-aaaa-7aaa-8aaa-aaaaaaaaaaaa'],
        fromDate: '2026-11-02',
        toDate: '2026-11-30',
        weekdays: [1],
        windows: [],
    );

    expect(fn () => $handler->handle($command))->toThrow(CapacityException::class, 'ventana');
});

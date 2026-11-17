<?php

declare(strict_types=1);

use Logistics\Outbox\Domain\OutboxMessage;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Domain\Event\DomainEvent;
use Logistics\Shared\Domain\ValueObject\Uuid;
use Logistics\Telemetry\Application\Command\RefreshVehicleEtas\RefreshVehicleEtas;
use Logistics\Telemetry\Infrastructure\Outbox\RefreshRerouteOnDeviation;

// Reactor RefreshRerouteOnDeviation: al empezar un desvío (telemetry.vehicle_deviation_started) encola el
// recálculo del reruteo por calles del vehículo (RefreshVehicleEtas). Ignora otros eventos y payloads vacíos.

/**
 * Envuelve un hecho en un OutboxMessage, como lo recibe el reactor.
 *
 * @param array<string, mixed> $payload
 */
function rrodOutbox(string $eventName, array $payload): OutboxMessage
{
    $event = new readonly class($eventName, $payload) implements DomainEvent
    {
        /** @param array<string, mixed> $payload */
        public function __construct(private string $name, private array $payload) {}

        public function eventId(): Uuid
        {
            return Uuid::generate();
        }

        public function aggregateId(): string
        {
            return 'veh-rrod';
        }

        public function eventName(): string
        {
            return $this->name;
        }

        public function occurredAt(): DateTimeImmutable
        {
            return new DateTimeImmutable('2026-11-17T12:00:00+00:00');
        }

        public function payload(): array
        {
            return $this->payload;
        }
    };

    return OutboxMessage::fromDomainEvent($event, 'telemetry.deviation', new DateTimeImmutable('2026-11-17T12:00:00+00:00'));
}

/**
 * CommandBus de prueba que acumula los comandos despachados.
 */
function rrodBus(): CommandBus
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

it('recalcula el reruteo del vehículo al empezar el desvío', function (): void {
    $bus = rrodBus();
    (new RefreshRerouteOnDeviation($bus))->dispatch(
        rrodOutbox('telemetry.vehicle_deviation_started', ['vehicle_id' => 'veh-1', 'since' => '2026-11-17T12:00:00+00:00']),
    );

    expect($bus->dispatched)->toHaveCount(1);
    $command = $bus->dispatched[0];
    expect($command)->toBeInstanceOf(RefreshVehicleEtas::class);
    assert($command instanceof RefreshVehicleEtas);
    expect($command->vehicleId)->toBe('veh-1');
});

it('ignora otros eventos y payloads sin vehículo', function (): void {
    $bus = rrodBus();
    $reactor = new RefreshRerouteOnDeviation($bus);

    $reactor->dispatch(rrodOutbox('telemetry.vehicle_position_reported', ['vehicle_id' => 'veh-1']));
    $reactor->dispatch(rrodOutbox('telemetry.vehicle_deviation_started', ['vehicle_id' => '']));
    $reactor->dispatch(rrodOutbox('telemetry.vehicle_deviation_started', []));

    expect($bus->dispatched)->toBe([]);
});

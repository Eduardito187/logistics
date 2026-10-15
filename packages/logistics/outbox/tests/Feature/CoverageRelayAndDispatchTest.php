<?php

declare(strict_types=1);

use Logistics\Outbox\Domain\OutboxMessage;
use Logistics\Outbox\Infrastructure\Dispatch\FanOutMessageDispatcher;
use Logistics\Outbox\Infrastructure\Dispatch\NullMessageDispatcher;
use Logistics\Outbox\Infrastructure\Persistence\Eloquent\OutboxMessageRecord;
use Logistics\Shared\Domain\Event\DomainEvent;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Cobertura del relay del outbox por consola y de los dispatchers de infraestructura (Null y FanOut). El
 * comando corre el lote real contra PostgreSQL con el NullMessageDispatcher por defecto (no sale nada al
 * exterior). Patrones C/D del brief de cobertura.
 */

/**
 * Construye un mensaje de outbox de dominio a partir de un evento mínimo (sin tocar la base).
 */
function coverageOutboxMessage(string $eventName): OutboxMessage
{
    $event = new readonly class($eventName, Uuid::generate()) implements DomainEvent
    {
        public function __construct(
            private string $name,
            private Uuid $id,
        ) {}

        public function eventId(): Uuid
        {
            return $this->id;
        }

        public function aggregateId(): string
        {
            return 'agg-coverage-1';
        }

        public function eventName(): string
        {
            return $this->name;
        }

        public function occurredAt(): DateTimeImmutable
        {
            return new DateTimeImmutable('2026-10-13T10:00:00+00:00');
        }

        public function payload(): array
        {
            return ['k' => 'v'];
        }
    };

    return OutboxMessage::fromDomainEvent($event, 'coverage.aggregate', new DateTimeImmutable('2026-10-13T10:00:00+00:00'));
}

it('despacha un lote del outbox desde la consola', function (): void {
    OutboxMessageRecord::factory()->count(2)->create();

    $this->artisan('logistics:outbox:relay', ['--limit' => 10])
        ->assertExitCode(0);
});

it('rechaza un límite de relay menor que uno', function (): void {
    $this->artisan('logistics:outbox:relay', ['--limit' => 0])
        ->assertExitCode(1);
});

it('el NullMessageDispatcher no envía nada y no revienta', function (): void {
    $dispatcher = new NullMessageDispatcher;

    $dispatcher->dispatch(coverageOutboxMessage('sap.stock_transfer_posted'));

    // Sin excepción: el camino sin logger queda cubierto.
    expect(true)->toBeTrue();
});

it('el FanOutMessageDispatcher entrega a los adapters configurados para el evento', function (): void {
    $fanout = new FanOutMessageDispatcher(app(), [
        'execution.vehicle_incident_reported' => [NullMessageDispatcher::class],
    ]);

    // Evento con abanico configurado: recorre los targets.
    $fanout->dispatch(coverageOutboxMessage('execution.vehicle_incident_reported'));
    // Evento sin abanico: no hay targets, no hace nada.
    $fanout->dispatch(coverageOutboxMessage('network.node_registered'));

    expect(true)->toBeTrue();
});

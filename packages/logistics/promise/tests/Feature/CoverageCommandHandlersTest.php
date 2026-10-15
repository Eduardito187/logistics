<?php

declare(strict_types=1);

use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Outbox\Domain\Contract\OutboxStore;
use Logistics\Outbox\Domain\OutboxMessage;
use Logistics\Promise\Application\Command\ConfirmPromise\ConfirmPromise;
use Logistics\Promise\Application\Command\ConfirmPromise\ConfirmPromiseHandler;
use Logistics\Promise\Application\Command\ExpireDueReservations\ExpireDueReservations;
use Logistics\Promise\Application\Command\ExpireDueReservations\ExpireDueReservationsHandler;
use Logistics\Promise\Application\Command\ExpireReservation\ExpireReservation;
use Logistics\Promise\Application\Command\ExpireReservation\ExpireReservationHandler;
use Logistics\Promise\Application\Command\QuotePromise\QuotePromise;
use Logistics\Promise\Application\Command\QuotePromise\QuotePromiseHandler;
use Logistics\Promise\Application\Command\ReserveDeliverySlot\ReserveDeliverySlot;
use Logistics\Promise\Application\Command\ReserveDeliverySlot\ReserveDeliverySlotHandler;
use Logistics\Promise\Application\Command\ReservePromise\ReservePromise;
use Logistics\Promise\Application\Command\ReservePromise\ReservePromiseHandler;
use Logistics\Promise\Application\Query\ListAvailableSlots\ListAvailableSlots;
use Logistics\Promise\Application\Query\ListAvailableSlots\ListAvailableSlotsHandler;
use Logistics\Promise\Application\ReadModel\AvailableSlotView;
use Logistics\Promise\Domain\Contract\AvailabilityProvider;
use Logistics\Promise\Domain\Contract\OrderDeliverySlotGateway;
use Logistics\Promise\Domain\Contract\PromiseRepository;
use Logistics\Promise\Domain\Contract\SlotReserver;
use Logistics\Promise\Domain\Exception\PromiseException;
use Logistics\Promise\Domain\Model\DeliveryPromise;
use Logistics\Promise\Domain\ValueObject\DeliverySlot;
use Logistics\Promise\Domain\ValueObject\PromiseId;
use Logistics\Promise\Domain\ValueObject\PromiseStatus;
use Logistics\Promise\Domain\ValueObject\ReservationWindow;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Domain\Clock\Clock;

/*
| Cobertura de los handlers de comando/consulta del contexto promise ejercitando su código real con
| dobles en memoria (mismo patrón que los tests Unit del contexto: el handler es PHP puro, sin DB). Se
| recorre el happy-path y las ramas de rechazo de cada handler no cubierto por la suite existente.
|
| Son pruebas de dominio/aplicación puras: no tocan la base, pero viven en Feature por convención del
| contexto. Los nombres de las funciones de ayuda van con prefijo único (cvp*) para no colisionar con
| las funciones globales que declaran otros archivos de la suite Pest.
*/

/** Reloj fijo para los handlers que piden el "ahora" al dominio. */
function cvpClock(string $iso = '2026-10-15T08:00:00'): Clock
{
    return new class($iso) implements Clock
    {
        public function __construct(private readonly string $iso) {}

        public function now(): DateTimeImmutable
        {
            return new DateTimeImmutable($this->iso);
        }
    };
}

/** OutboxStore de memoria para armar un RecordDomainEvents real sin tocar la base. */
function cvpOutboxStore(): OutboxStore
{
    return new class implements OutboxStore
    {
        public function append(OutboxMessage $message): void {}

        public function pullBatch(int $limit, DateTimeImmutable $now): array
        {
            return [];
        }

        public function save(OutboxMessage $message): void {}
    };
}

/** Puente real a outbox con dependencias en memoria (record() es no-op si no hay eventos). */
function cvpRecorder(): RecordDomainEvents
{
    return new RecordDomainEvents(cvpOutboxStore(), cvpClock());
}

/** Repositorio de promesas en memoria: guarda por id y permite sembrar una promesa inicial. */
function cvpRepo(?DeliveryPromise $seed = null): PromiseRepository
{
    return new class($seed) implements PromiseRepository
    {
        /** @var array<string, DeliveryPromise> */
        public array $store = [];

        /** @var list<DeliveryPromise> */
        public array $saved = [];

        public function __construct(?DeliveryPromise $seed)
        {
            if ($seed !== null) {
                $this->store[$seed->id()->toString()] = $seed;
            }
        }

        public function nextIdentity(): PromiseId
        {
            return PromiseId::generate();
        }

        public function save(DeliveryPromise $promise): void
        {
            $this->store[$promise->id()->toString()] = $promise;
            $this->saved[] = $promise;
        }

        public function findById(PromiseId $id): ?DeliveryPromise
        {
            return $this->store[$id->toString()] ?? null;
        }

        public function reservedExpiredAt(DateTimeImmutable $now): array
        {
            return array_values(array_map(
                static fn (DeliveryPromise $p): PromiseId => $p->id(),
                array_values($this->store),
            ));
        }
    };
}

/** Reservador de cupo en memoria: éxito/fallo configurable, registra reserve/release. */
function cvpReserver(bool $succeeds): SlotReserver
{
    return new class($succeeds) implements SlotReserver
    {
        /** @var list<string> */
        public array $reserved = [];

        /** @var list<string> */
        public array $released = [];

        public function __construct(private readonly bool $succeeds) {}

        public function reserve(string $zoneId, DeliverySlot $slot): bool
        {
            $this->reserved[] = $zoneId.'|'.$slot->toCanonical();

            return $this->succeeds;
        }

        public function release(string $zoneId, DeliverySlot $slot): void
        {
            $this->released[] = $zoneId.'|'.$slot->toCanonical();
        }
    };
}

/**
 * Pasarela de envío en memoria: devuelve la franja vigente dada y registra las asignaciones. Si se
 * construye con $throwOnAssign, assignSlot lanza una PromiseException (para la rama best-effort).
 *
 * @param array{date: string, window_start: string, window_end: string}|null $current
 */
function cvpOrders(?array $current = null, bool $throwOnAssign = false): OrderDeliverySlotGateway
{
    return new class($current, $throwOnAssign) implements OrderDeliverySlotGateway
    {
        /** @var list<array{order: string, date: string, start: string, end: string}> */
        public array $assigned = [];

        /** @param array{date: string, window_start: string, window_end: string}|null $current */
        public function __construct(
            private readonly ?array $current,
            private readonly bool $throwOnAssign,
        ) {}

        public function currentSlot(string $orderId): ?array
        {
            return $this->current;
        }

        public function assignSlot(string $orderId, string $date, string $windowStart, string $windowEnd): void
        {
            if ($this->throwOnAssign) {
                throw PromiseException::because('el pedido no admite cambio de franja');
            }

            $this->assigned[] = ['order' => $orderId, 'date' => $date, 'start' => $windowStart, 'end' => $windowEnd];
        }
    };
}

/**
 * Proveedor de disponibilidad en memoria: devuelve las franjas dadas para cualquier zona/fecha.
 *
 * @param list<DeliverySlot> $slots
 */
function cvpAvailability(array $slots): AvailabilityProvider
{
    return new class($slots) implements AvailabilityProvider
    {
        /** @param list<DeliverySlot> $slots */
        public function __construct(private readonly array $slots) {}

        public function availableSlots(string $zoneId, DateTimeImmutable $date): array
        {
            return $this->slots;
        }
    };
}

/** Promesa reservada reconstituida con ventana a futuro y la franja indicada. */
function cvpReservedPromise(string $timeSlot = 'AM'): DeliveryPromise
{
    return DeliveryPromise::reconstitute(
        PromiseId::generate(),
        'SHIP-260000001',
        '0191c2a0-1111-7000-8000-000000000001',
        DeliverySlot::of(new DateTimeImmutable('2026-10-20'), $timeSlot),
        PromiseStatus::Reserved,
        ReservationWindow::until(new DateTimeImmutable('2026-10-21T00:00:00')),
    );
}

// ---------------------------------------------------------------------------
// QuotePromiseHandler
// ---------------------------------------------------------------------------

it('cotiza una promesa cuando la franja pedida tiene cupo y expone su id', function (): void {
    $slot = DeliverySlot::of(new DateTimeImmutable('2026-10-20'), 'AM');
    $repo = cvpRepo();
    $handler = new QuotePromiseHandler($repo, cvpAvailability([$slot]), cvpRecorder());

    $handler->handle(new QuotePromise('SHIP-1', 'zone-1', '2026-10-20', 'AM'));

    expect($repo->saved)->toHaveCount(1)
        ->and($handler->lastPromiseId())->toBeInstanceOf(PromiseId::class)
        ->and($repo->saved[0]->status())->toBe(PromiseStatus::Quoted);
});

it('rechaza cotizar una franja sin cupo disponible', function (): void {
    $handler = new QuotePromiseHandler(cvpRepo(), cvpAvailability([]), cvpRecorder());

    expect(fn () => $handler->handle(new QuotePromise('SHIP-1', 'zone-1', '2026-10-20', 'AM')))
        ->toThrow(PromiseException::class);
});

it('rechaza cotizar con una fecha de franja inválida', function (): void {
    $handler = new QuotePromiseHandler(cvpRepo(), cvpAvailability([]), cvpRecorder());

    expect(fn () => $handler->handle(new QuotePromise('SHIP-1', 'zone-1', '20-10-2026', 'AM')))
        ->toThrow(PromiseException::class);
});

it('falla al pedir lastPromiseId antes de cotizar', function (): void {
    $handler = new QuotePromiseHandler(cvpRepo(), cvpAvailability([]), cvpRecorder());

    expect(fn () => $handler->lastPromiseId())->toThrow(PromiseException::class);
});

// ---------------------------------------------------------------------------
// ConfirmPromiseHandler
// ---------------------------------------------------------------------------

it('confirma una promesa reservada con franja etiqueta y no concilia el pedido', function (): void {
    $promise = cvpReservedPromise('AM');
    $orders = cvpOrders();
    $handler = new ConfirmPromiseHandler(cvpRepo($promise), cvpClock(), cvpRecorder(), $orders);

    $handler->handle(new ConfirmPromise($promise->id()->toString()));

    expect($promise->status())->toBe(PromiseStatus::Confirmed)
        ->and($orders->assigned)->toBe([]);
});

it('confirma una promesa reservada con franja de rango y concilia el pedido', function (): void {
    $promise = cvpReservedPromise('09:00-13:00');
    $orders = cvpOrders();
    $handler = new ConfirmPromiseHandler(cvpRepo($promise), cvpClock(), cvpRecorder(), $orders);

    $handler->handle(new ConfirmPromise($promise->id()->toString()));

    expect($promise->status())->toBe(PromiseStatus::Confirmed)
        ->and($orders->assigned)->toHaveCount(1)
        ->and($orders->assigned[0]['start'])->toBe('09:00')
        ->and($orders->assigned[0]['end'])->toBe('13:00');
});

it('confirma aunque la conciliación del pedido falle (best-effort)', function (): void {
    $promise = cvpReservedPromise('09:00-13:00');
    $orders = cvpOrders(null, throwOnAssign: true);
    $handler = new ConfirmPromiseHandler(cvpRepo($promise), cvpClock(), cvpRecorder(), $orders);

    $handler->handle(new ConfirmPromise($promise->id()->toString()));

    expect($promise->status())->toBe(PromiseStatus::Confirmed);
});

it('rechaza confirmar una promesa inexistente', function (): void {
    $handler = new ConfirmPromiseHandler(cvpRepo(), cvpClock(), cvpRecorder(), cvpOrders());

    expect(fn () => $handler->handle(new ConfirmPromise(PromiseId::generate()->toString())))
        ->toThrow(PromiseException::class);
});

// ---------------------------------------------------------------------------
// ReservePromiseHandler
// ---------------------------------------------------------------------------

it('reserva una promesa cotizada tomando cupo', function (): void {
    $promise = DeliveryPromise::quote(
        PromiseId::generate(),
        'SHIP-1',
        'zone-1',
        DeliverySlot::of(new DateTimeImmutable('2026-10-20'), 'AM'),
    );
    $reserver = cvpReserver(true);
    $handler = new ReservePromiseHandler(cvpRepo($promise), $reserver, cvpClock(), cvpRecorder());

    $handler->handle(new ReservePromise($promise->id()->toString(), 30));

    expect($promise->status())->toBe(PromiseStatus::Reserved)
        ->and($reserver->reserved)->toHaveCount(1);
});

it('rechaza reservar cuando la capacidad está agotada', function (): void {
    $promise = DeliveryPromise::quote(
        PromiseId::generate(),
        'SHIP-1',
        'zone-1',
        DeliverySlot::of(new DateTimeImmutable('2026-10-20'), 'AM'),
    );
    $handler = new ReservePromiseHandler(cvpRepo($promise), cvpReserver(false), cvpClock(), cvpRecorder());

    expect(fn () => $handler->handle(new ReservePromise($promise->id()->toString(), 30)))
        ->toThrow(PromiseException::class);
});

it('rechaza reservar una promesa inexistente', function (): void {
    $handler = new ReservePromiseHandler(cvpRepo(), cvpReserver(true), cvpClock(), cvpRecorder());

    expect(fn () => $handler->handle(new ReservePromise(PromiseId::generate()->toString(), 30)))
        ->toThrow(PromiseException::class);
});

// ---------------------------------------------------------------------------
// ExpireReservationHandler
// ---------------------------------------------------------------------------

it('expira una promesa reservada y libera su cupo', function (): void {
    $promise = cvpReservedPromise('AM');
    $reserver = cvpReserver(true);
    $handler = new ExpireReservationHandler(cvpRepo($promise), $reserver, cvpRecorder());

    $handler->handle(new ExpireReservation($promise->id()->toString()));

    expect($promise->status())->toBe(PromiseStatus::Expired)
        ->and($reserver->released)->toHaveCount(1);
});

it('rechaza expirar una promesa inexistente', function (): void {
    $handler = new ExpireReservationHandler(cvpRepo(), cvpReserver(true), cvpRecorder());

    expect(fn () => $handler->handle(new ExpireReservation(PromiseId::generate()->toString())))
        ->toThrow(PromiseException::class);
});

// ---------------------------------------------------------------------------
// ReserveDeliverySlotHandler
// ---------------------------------------------------------------------------

it('reserva una franja de despacho nueva: toma cupo y la fija en el pedido', function (): void {
    $reserver = cvpReserver(true);
    $orders = cvpOrders(null);
    $handler = new ReserveDeliverySlotHandler($reserver, $orders);

    $handler->handle(new ReserveDeliverySlot(
        '0192f0a0-0000-7000-8000-00000000aaaa',
        'zone-1',
        '2026-10-20',
        '09:00',
        '13:00',
    ));

    expect($reserver->reserved)->toHaveCount(1)
        ->and($reserver->released)->toBe([])
        ->and($orders->assigned)->toHaveCount(1);
});

it('es idempotente: reservar la misma franja ya vigente no consume cupo', function (): void {
    $reserver = cvpReserver(true);
    $orders = cvpOrders(['date' => '2026-10-20', 'window_start' => '09:00', 'window_end' => '13:00']);
    $handler = new ReserveDeliverySlotHandler($reserver, $orders);

    $handler->handle(new ReserveDeliverySlot(
        '0192f0a0-0000-7000-8000-00000000aaaa',
        'zone-1',
        '2026-10-20',
        '09:00',
        '13:00',
    ));

    expect($reserver->reserved)->toBe([])
        ->and($orders->assigned)->toBe([]);
});

it('reprograma: libera la franja anterior antes de tomar la nueva', function (): void {
    $reserver = cvpReserver(true);
    $orders = cvpOrders(['date' => '2026-10-20', 'window_start' => '09:00', 'window_end' => '13:00']);
    $handler = new ReserveDeliverySlotHandler($reserver, $orders);

    $handler->handle(new ReserveDeliverySlot(
        '0192f0a0-0000-7000-8000-00000000aaaa',
        'zone-1',
        '2026-10-20',
        '14:00',
        '18:00',
    ));

    expect($reserver->released)->toHaveCount(1)
        ->and($reserver->reserved)->toHaveCount(1);
});

it('rechaza reservar una franja de despacho con capacidad agotada', function (): void {
    $handler = new ReserveDeliverySlotHandler(cvpReserver(false), cvpOrders(null));

    expect(fn () => $handler->handle(new ReserveDeliverySlot(
        '0192f0a0-0000-7000-8000-00000000aaaa',
        'zone-1',
        '2026-10-20',
        '09:00',
        '13:00',
    )))->toThrow(PromiseException::class);
});

it('rechaza reservar una franja de despacho con fecha inválida', function (): void {
    $handler = new ReserveDeliverySlotHandler(cvpReserver(true), cvpOrders(null));

    expect(fn () => $handler->handle(new ReserveDeliverySlot(
        '0192f0a0-0000-7000-8000-00000000aaaa',
        'zone-1',
        '20-10-2026',
        '09:00',
        '13:00',
    )))->toThrow(PromiseException::class);
});

// ---------------------------------------------------------------------------
// ExpireDueReservationsHandler (barrido en lote)
// ---------------------------------------------------------------------------

it('barre reservas vencidas contando éxitos y fallos por carrera', function (): void {
    // Dos promesas reservadas vencidas en el repo; el bus falla la segunda (carrera de estado).
    $one = cvpReservedPromise('AM');
    $two = cvpReservedPromise('PM');
    $repo = cvpRepo($one);
    $repo->save($two);

    $bus = new class implements CommandBus
    {
        public int $calls = 0;

        public function dispatch(Command $command): void
        {
            $this->calls++;
            if ($this->calls === 2) {
                throw PromiseException::because('ya fue confirmada o expirada');
            }
        }
    };

    $handler = new ExpireDueReservationsHandler($repo, cvpClock(), $bus);
    $handler->handle(new ExpireDueReservations);

    expect($handler->lastExpired())->toBe(1)
        ->and($handler->lastFailed())->toBe(1);
});

// ---------------------------------------------------------------------------
// ListAvailableSlotsHandler (consulta)
// ---------------------------------------------------------------------------

it('lista las franjas disponibles como vistas de lectura', function (): void {
    $slots = [
        DeliverySlot::of(new DateTimeImmutable('2026-10-20'), '09:00-13:00'),
        DeliverySlot::of(new DateTimeImmutable('2026-10-20'), '14:00-18:00'),
    ];
    $handler = new ListAvailableSlotsHandler(cvpAvailability($slots));

    $views = $handler->handle(new ListAvailableSlots('zone-1', '2026-10-20'));

    expect($views)->toHaveCount(2)
        ->and($views[0])->toBeInstanceOf(AvailableSlotView::class)
        ->and($views[0]->toArray())->toBe(['slot_date' => '2026-10-20', 'time_slot' => '09:00-13:00']);
});

it('rechaza listar franjas con una fecha inválida', function (): void {
    $handler = new ListAvailableSlotsHandler(cvpAvailability([]));

    expect(fn () => $handler->handle(new ListAvailableSlots('zone-1', '20-10-2026')))
        ->toThrow(PromiseException::class);
});

<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Logistics\Inbound\Application\Command\AnnounceAsn\AnnounceAsn;
use Logistics\Inbound\Application\Command\CancelAsn\CancelAsn;
use Logistics\Inbound\Application\Command\ScheduleAsn\ScheduleAsn;
use Logistics\Inbound\Application\Query\GetAsn\GetAsn;
use Logistics\Inbound\Application\Query\GetReceivingDockCalendar\GetReceivingDockCalendar;
use Logistics\Inbound\Application\Query\ListAsns\ListAsns;
use Logistics\Inbound\Application\Query\ListPendingArrivals\ListPendingArrivals;
use Logistics\Inbound\Application\ReadModel\AsnView;
use Logistics\Inbound\Application\ReadModel\ReceivingDockDayView;
use Logistics\Inbound\Domain\Contract\DockQuotaReserver;
use Logistics\Inbound\Domain\Exception\InboundException;
use Logistics\Inbound\Domain\ValueObject\AppointmentWindow;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Cobertura de las consultas CQRS de inbound no ejercitadas por el flujo núcleo (GetAsn,
 * GetReceivingDockCalendar, ListPendingArrivals) y del comando CancelAsn, contra logistics_test.
 * El cupo de andén se reemplaza por un doble (el flujo no depende de capacity). Skip sin PostgreSQL.
 */
beforeEach(function (): void {
    $this->commands = app(CommandBus::class);
    $this->queries = app(QueryBus::class);

    app()->instance(DockQuotaReserver::class, new class implements DockQuotaReserver
    {
        public function reserve(string $warehouseId, AppointmentWindow $window): void
        {
            // Doble inerte: el agendamiento no consume capacity real en estos tests.
        }
    });
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

/**
 * Anuncia un ASN por el bus y devuelve su id, resolviéndolo por el seller.
 *
 * @param list<array{sku: string, expected_quantity: int}> $lines
 */
function inboundSeedAsn(
    CommandBus $bus,
    string $sellerId,
    string $warehouseId = '01931001-0000-7000-8000-0000000000f1',
    array $lines = [['sku' => 'BEL-TV-55', 'expected_quantity' => 10]],
): string {
    $bus->dispatch(new AnnounceAsn(sellerId: $sellerId, warehouseId: $warehouseId, lines: $lines));

    /** @var list<AsnView> $asns */
    $asns = app(QueryBus::class)->ask(new ListAsns(sellerId: $sellerId));

    return $asns[0]->id;
}

it('GetAsn devuelve el ASN como ReadModel y null si no existe', function (): void {
    $id = inboundSeedAsn($this->commands, '01931000-0000-7000-8000-0000000000a1');

    /** @var AsnView|null $view */
    $view = $this->queries->ask(new GetAsn($id));
    expect($view)->not->toBeNull()
        ->and($view->id)->toBe($id)
        ->and($view->status)->toBe('announced')
        ->and($view->lines)->toHaveCount(1)
        ->and($view->appointment)->toBeNull();

    $missing = $this->queries->ask(new GetAsn('01931000-0000-7000-8000-0000000000ff'));
    expect($missing)->toBeNull();
});

it('CancelAsn pasa a Cancelled y publica el evento al outbox', function (): void {
    $id = inboundSeedAsn($this->commands, '01931000-0000-7000-8000-0000000000a2');

    $this->commands->dispatch(new CancelAsn($id, 'seller no se presentó'));

    $this->assertDatabaseHas('inbound_asns', ['id' => $id, 'status' => 'cancelled']);
    expect(DB::table('outbox_messages')->where('event_name', 'inbound.asn_cancelled')->count())->toBeGreaterThanOrEqual(1);
});

it('CancelAsn sobre un id inexistente lanza la excepción de dominio', function (): void {
    expect(fn () => $this->commands->dispatch(new CancelAsn('01931000-0000-7000-8000-0000000000fe')))
        ->toThrow(InboundException::class);
});

it('ListPendingArrivals devuelve solo los ASNs agendados', function (): void {
    $seller = '01931000-0000-7000-8000-0000000000a3';
    $scheduledId = inboundSeedAsn($this->commands, $seller);
    $this->commands->dispatch(new ScheduleAsn($scheduledId, '2026-10-20', '08:00', '12:00'));

    // Un segundo ASN que queda solo anunciado (no debe salir en pendientes de llegada).
    inboundSeedAsn($this->commands, $seller);

    /** @var list<AsnView> $pending */
    $pending = $this->queries->ask(new ListPendingArrivals);
    $ids = array_map(static fn (AsnView $v): string => $v->id, $pending);

    expect($ids)->toContain($scheduledId)
        ->and($pending)->toHaveCount(1)
        ->and($pending[0]->status)->toBe('scheduled');
});

it('GetReceivingDockCalendar agrega las citas por día y franja', function (): void {
    $warehouse = '01931001-0000-7000-8000-0000000000c1';

    // Un seller DISTINTO por ASN: inboundSeedAsn devuelve el ASN del seller (ListAsns por seller), así que
    // con el mismo seller las tres llamadas devolverían el MISMO id (el primero) y no habría 3 citas.
    $first = inboundSeedAsn($this->commands, '01931000-0000-7000-8000-0000000000a4', $warehouse);
    $this->commands->dispatch(new ScheduleAsn($first, '2026-10-20', '08:00', '12:00'));

    $second = inboundSeedAsn($this->commands, '01931000-0000-7000-8000-0000000000a5', $warehouse);
    $this->commands->dispatch(new ScheduleAsn($second, '2026-10-20', '08:00', '12:00'));

    $third = inboundSeedAsn($this->commands, '01931000-0000-7000-8000-0000000000a6', $warehouse);
    $this->commands->dispatch(new ScheduleAsn($third, '2026-10-21', '14:00', '18:00'));

    /** @var list<ReceivingDockDayView> $calendar */
    $calendar = $this->queries->ask(new GetReceivingDockCalendar(
        fromDate: '2026-10-20',
        days: 2,
        warehouseId: $warehouse,
    ));

    expect($calendar)->toHaveCount(2)
        ->and($calendar[0]->date)->toBe('2026-10-20')
        ->and($calendar[0]->appointments)->toBe(2)
        ->and($calendar[0]->slots[0])->toBe(['time_slot' => '08:00-12:00', 'appointments' => 2])
        ->and($calendar[1]->date)->toBe('2026-10-21')
        ->and($calendar[1]->appointments)->toBe(1);
});

it('GetReceivingDockCalendar normaliza days a un mínimo de 1 y da días vacíos', function (): void {
    /** @var list<ReceivingDockDayView> $calendar */
    $calendar = $this->queries->ask(new GetReceivingDockCalendar(fromDate: '2026-10-20', days: 0));

    expect($calendar)->toHaveCount(1)
        ->and($calendar[0]->date)->toBe('2026-10-20')
        ->and($calendar[0]->appointments)->toBe(0)
        ->and($calendar[0]->slots)->toBe([]);
});

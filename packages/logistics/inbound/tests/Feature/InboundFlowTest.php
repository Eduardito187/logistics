<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Logistics\Inbound\Application\Command\AnnounceAsn\AnnounceAsn;
use Logistics\Inbound\Application\Command\CloseAsn\CloseAsn;
use Logistics\Inbound\Application\Command\LabelReceivedUnits\LabelReceivedUnits;
use Logistics\Inbound\Application\Command\RecordArrival\RecordArrival;
use Logistics\Inbound\Application\Command\RecordGoodsReceipt\RecordGoodsReceipt;
use Logistics\Inbound\Application\Command\ScheduleAsn\ScheduleAsn;
use Logistics\Inbound\Application\Query\GetGoodsReceipt\GetGoodsReceipt;
use Logistics\Inbound\Application\Query\ListAsns\ListAsns;
use Logistics\Inbound\Application\ReadModel\AsnView;
use Logistics\Inbound\Application\ReadModel\GoodsReceiptView;
use Logistics\Inbound\Domain\Contract\DockQuotaReserver;
use Logistics\Inbound\Domain\ValueObject\AppointmentWindow;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Stack completo del contexto Inbound contra logistics_test: command → handler → repositorio + outbox, y
 * las consultas CQRS. El cupo de andén se reemplaza por un doble (el flujo núcleo no depende de capacity).
 * Skip sin PostgreSQL.
 */
beforeEach(function (): void {
    $this->commands = app(CommandBus::class);
    $this->queries = app(QueryBus::class);

    // Doble del puerto de cupo de andén: deja testear announce → schedule → arrive → receipt → label sin
    // que capacity tenga cupos definidos (contrato estable aunque capacity aún no exponga el eje inbound).
    $this->reserved = [];
    app()->instance(DockQuotaReserver::class, new class($this->reserved) implements DockQuotaReserver
    {
        /** @param array<int, string> $reserved */
        public function __construct(private array &$reserved) {}

        public function reserve(string $warehouseId, AppointmentWindow $window): void
        {
            $this->reserved[] = $warehouseId.'|'.$window->date.'|'.$window->timeSlot();
        }
    });
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

function inboundAnnounceViaBus(CommandBus $bus, string $sellerId = '01931000-0000-7000-8000-000000000001'): string
{
    $bus->dispatch(new AnnounceAsn(
        sellerId: $sellerId,
        warehouseId: '01931001-0000-7000-8000-0000000000f1',
        lines: [['sku' => 'BEL-TV-55', 'expected_quantity' => 10]],
    ));

    /** @var list<AsnView> $asns */
    $asns = app(QueryBus::class)->ask(new ListAsns(sellerId: $sellerId));

    return $asns[0]->id;
}

it('recibe un producto FBD de punta a punta: announce → schedule → arrive → receipt → label', function (): void {
    $id = inboundAnnounceViaBus($this->commands);

    $this->assertDatabaseHas('inbound_asns', ['id' => $id, 'status' => 'announced']);

    $this->commands->dispatch(new ScheduleAsn($id, '2026-10-20', '08:00', '12:00'));
    expect($this->reserved)->toHaveCount(1);
    $this->assertDatabaseHas('inbound_asns', ['id' => $id, 'status' => 'scheduled']);

    $this->commands->dispatch(new RecordArrival($id, 'TRUCK-9'));
    $this->assertDatabaseHas('inbound_asns', ['id' => $id, 'status' => 'arrived']);

    $this->commands->dispatch(new RecordGoodsReceipt($id, [
        ['sku' => 'BEL-TV-55', 'accepted' => 9, 'rejected' => 0, 'damaged' => 1, 'qc_outcome' => 'partial_damage'],
    ]));
    $this->assertDatabaseHas('inbound_asns', ['id' => $id, 'status' => 'received']);

    /** @var GoodsReceiptView|null $receipt */
    $receipt = $this->queries->ask(new GetGoodsReceipt($id));
    expect($receipt)->not->toBeNull()
        ->and($receipt->totalAccepted)->toBe(9)
        ->and($receipt->fulfillmentMode)->toBe('fbd');

    $this->commands->dispatch(new LabelReceivedUnits($id));

    /** @var GoodsReceiptView|null $labeled */
    $labeled = $this->queries->ask(new GetGoodsReceipt($id));
    expect($labeled->labeled)->toBeTrue();

    $this->commands->dispatch(new CloseAsn($id));
    $this->assertDatabaseHas('inbound_asns', ['id' => $id, 'status' => 'closed']);

    // El outbox recibió los hechos clave (convergencia incluida).
    expect(DB::table('outbox_messages')->where('event_name', 'inbound.goods_received')->count())->toBeGreaterThanOrEqual(1)
        ->and(DB::table('outbox_messages')->where('event_name', 'inbound.units_labeled')->count())->toBeGreaterThanOrEqual(1);
});

it('lista ASNs filtrando por estado', function (): void {
    $id = inboundAnnounceViaBus($this->commands, '01931000-0000-7000-8000-000000000002');
    $this->commands->dispatch(new ScheduleAsn($id, '2026-10-21', '14:00', '18:00'));

    /** @var list<AsnView> $scheduled */
    $scheduled = $this->queries->ask(new ListAsns(status: 'scheduled'));
    $ids = array_map(static fn (AsnView $v): string => $v->id, $scheduled);

    expect($ids)->toContain($id);
});

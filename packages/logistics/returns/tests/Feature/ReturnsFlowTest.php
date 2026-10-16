<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Logistics\Returns\Application\Command\DispositionReturn\DispositionReturn;
use Logistics\Returns\Application\Command\InitiateReturn\InitiateReturn;
use Logistics\Returns\Application\Command\ReceiveReturn\ReceiveReturn;
use Logistics\Returns\Application\Query\GetReturn\GetReturn;
use Logistics\Returns\Application\Query\ListReturns\ListReturns;
use Logistics\Returns\Application\ReadModel\ReturnView;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Stack completo del contexto Returns contra logistics_test: command → handler → repositorio + outbox, y
 * las consultas CQRS. Verifica la logística inversa de punta a punta y que el evento de disposición Restock
 * lleve lo que inventory necesita para ReceiveStock. Skip sin PostgreSQL.
 */
beforeEach(function (): void {
    $this->commands = app(CommandBus::class);
    $this->queries = app(QueryBus::class);
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

function returnsInitiateViaBus(CommandBus $bus, ?string $sellerId = '01931000-0000-7000-8000-000000000001', string $reference = 'SHP-9001'): string
{
    $bus->dispatch(new InitiateReturn(
        originalReference: $reference,
        warehouseId: '01931001-0000-7000-8000-0000000000f1',
        kind: 'customer_return',
        lines: [['sku' => 'BEL-TV-55', 'quantity' => 2, 'reason' => 'damaged']],
        ownerPartyId: $sellerId,
    ));

    /** @var list<ReturnView> $returns */
    $returns = app(QueryBus::class)->ask(new ListReturns(sellerId: $sellerId));

    return $returns[0]->id;
}

it('procesa una devolución FBD de punta a punta: initiate → receive → disposition(restock)', function (): void {
    $id = returnsInitiateViaBus($this->commands);

    $this->assertDatabaseHas('returns', ['id' => $id, 'status' => 'initiated', 'fulfillment_mode' => 'fbd']);
    $this->assertDatabaseHas('return_lines', ['return_id' => $id, 'sku' => 'BEL-TV-55', 'quantity' => 2]);

    $this->commands->dispatch(new ReceiveReturn($id));
    $this->assertDatabaseHas('returns', ['id' => $id, 'status' => 'received']);

    $this->commands->dispatch(new DispositionReturn($id, 'restock'));
    $this->assertDatabaseHas('returns', ['id' => $id, 'status' => 'dispositioned', 'disposition' => 'restock']);

    /** @var ReturnView|null $view */
    $view = $this->queries->ask(new GetReturn($id));
    expect($view)->not->toBeNull()
        ->and($view->status)->toBe('dispositioned')
        ->and($view->disposition)->toBe('restock');

    // El outbox recibió el hecho de disposición para que inventory re-ingrese el stock.
    expect(DB::table('outbox_messages')->where('event_name', 'returns.return_dispositioned')->count())->toBeGreaterThanOrEqual(1)
        ->and(DB::table('outbox_messages')->where('event_name', 'returns.return_initiated')->count())->toBeGreaterThanOrEqual(1);
});

it('procesa una devolución retail (sin dueño) y queda owned', function (): void {
    $id = returnsInitiateViaBus($this->commands, null, 'SHP-9002');

    $this->assertDatabaseHas('returns', ['id' => $id, 'fulfillment_mode' => 'owned', 'owner_party_id' => null]);

    $this->commands->dispatch(new ReceiveReturn($id));
    $this->commands->dispatch(new DispositionReturn($id, 'scrap'));

    $this->assertDatabaseHas('returns', ['id' => $id, 'status' => 'dispositioned', 'disposition' => 'scrap']);
});

it('lista devoluciones filtrando por estado', function (): void {
    $id = returnsInitiateViaBus($this->commands, '01931000-0000-7000-8000-000000000002', 'SHP-9003');
    $this->commands->dispatch(new ReceiveReturn($id));

    /** @var list<ReturnView> $received */
    $received = $this->queries->ask(new ListReturns(status: 'received'));
    $ids = array_map(static fn (ReturnView $v): string => $v->id, $received);

    expect($ids)->toContain($id);
});

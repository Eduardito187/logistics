<?php

declare(strict_types=1);

use Commerce\Orders\Application\Command\MarkOrderPaid\MarkOrderPaid;
use Commerce\Orders\Application\Command\PlaceOrder\PlaceOrder;
use Commerce\Orders\Application\Command\RecordInitialPayment\RecordInitialPayment;
use Commerce\Orders\Application\Command\SignOrderContract\SignOrderContract;
use Commerce\Orders\Application\Query\GetOrder\GetOrder;
use Commerce\Orders\Application\Query\ListOrders\ListOrders;
use Commerce\Orders\Infrastructure\Persistence\Eloquent\Model\OrderRecord;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Ciclo de vida de una orden contra PostgreSQL real (logistics_test, RefreshDatabase): checkout →
 * pago → consulta. Atraviesa CommandBus → handler → repositorio → mapper → persistencia → QueryBus.
 */
beforeEach(function (): void {
    $this->commands = app(CommandBus::class);
    $this->queries = app(QueryBus::class);
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

it('crea una orden en checkout, la paga y la consulta', function (): void {
    $this->commands->dispatch(new PlaceOrder(
        customerName: 'Ana Pérez',
        addressText: 'Av. Siempre Viva 123, Santa Cruz',
        lines: [
            ['sku' => 'SKU-TV-55', 'product_name' => 'Televisor', 'unit_price_cents' => 450000, 'quantity' => 1],
            ['sku' => 'SKU-WM-12', 'product_name' => 'Lavadora', 'unit_price_cents' => 300000, 'quantity' => 2],
        ],
    ));

    $pending = $this->queries->ask(new ListOrders('pending'));
    expect($pending)->toHaveCount(1);
    $orderId = $pending[0]->id;
    expect($pending[0]->totalCents)->toBe(1050000);

    $this->commands->dispatch(new MarkOrderPaid($orderId, 'STUB-REF-1'));

    $view = $this->queries->ask(new GetOrder($orderId));
    expect($view)->not->toBeNull()
        ->and($view->status)->toBe('paid')
        ->and($view->shipTo['customer_name'])->toBe('Ana Pérez')
        ->and($view->lines)->toHaveCount(2);

    // La orden pagada quedó persistida con su estado.
    expect(OrderRecord::query()->where('id', $orderId)->value('status'))->toBe('paid');
});

it('MarkOrderPaid es idempotente a nivel de comando', function (): void {
    $this->commands->dispatch(new PlaceOrder(
        customerName: 'Luis',
        addressText: 'Calle 1',
        lines: [['sku' => 'SKU-X', 'product_name' => 'X', 'unit_price_cents' => 1000, 'quantity' => 1]],
    ));
    $orderId = $this->queries->ask(new ListOrders)[0]->id;

    $this->commands->dispatch(new MarkOrderPaid($orderId, 'REF'));
    $this->commands->dispatch(new MarkOrderPaid($orderId, 'REF'));

    expect($this->queries->ask(new GetOrder($orderId))->status)->toBe('paid');
});

it('crédito con inicial: pasa a procesando solo con contrato + inicial (end-to-end)', function (): void {
    $this->commands->dispatch(new PlaceOrder(
        customerName: 'Rosa',
        addressText: 'Calle 2',
        lines: [['sku' => 'SKU-Y', 'product_name' => 'Y', 'unit_price_cents' => 500000, 'quantity' => 1]],
        financing: 'credit_with_initial',
    ));
    $orderId = $this->queries->ask(new ListOrders('pending'))[0]->id;

    // Solo contrato: sigue pendiente.
    $this->commands->dispatch(new SignOrderContract($orderId, 'C-1'));
    expect(OrderRecord::query()->where('id', $orderId)->value('status'))->toBe('pending');

    // + inicial: pasa a pagada (procesando).
    $this->commands->dispatch(new RecordInitialPayment($orderId, 'I-1'));
    expect(OrderRecord::query()->where('id', $orderId)->value('status'))->toBe('paid');
    expect((bool) OrderRecord::query()->where('id', $orderId)->value('contract_signed'))->toBeTrue();
    expect((bool) OrderRecord::query()->where('id', $orderId)->value('initial_paid'))->toBeTrue();
});

it('crédito sin inicial: pasa a procesando solo con la firma del contrato (end-to-end)', function (): void {
    $this->commands->dispatch(new PlaceOrder(
        customerName: 'Beto',
        addressText: 'Calle 3',
        lines: [['sku' => 'SKU-Z', 'product_name' => 'Z', 'unit_price_cents' => 200000, 'quantity' => 1]],
        financing: 'credit_without_initial',
    ));
    $orderId = $this->queries->ask(new ListOrders('pending'))[0]->id;

    $this->commands->dispatch(new SignOrderContract($orderId, 'C-9'));

    expect(OrderRecord::query()->where('id', $orderId)->value('status'))->toBe('paid');
    expect($this->queries->ask(new GetOrder($orderId))->status)->toBe('paid');
});

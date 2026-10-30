<?php

declare(strict_types=1);

use Commerce\Invoicing\Application\Command\CancelInvoice\CancelInvoice;
use Commerce\Invoicing\Application\Command\IssueInvoiceForOrder\IssueInvoiceForOrder;
use Commerce\Invoicing\Application\Query\GetInvoice\GetInvoice;
use Commerce\Invoicing\Application\Query\GetInvoiceByOrder\GetInvoiceByOrder;
use Commerce\Invoicing\Application\Query\ListInvoices\ListInvoices;
use Commerce\Invoicing\Application\ReadModel\InvoiceView;
use Commerce\Invoicing\Infrastructure\Persistence\Eloquent\Model\InvoiceRecord;
use Illuminate\Support\Facades\DB;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Stack completo del contexto Invoicing contra logistics_test: command → handler → repositorio +
 * adaptador tributario stub + outbox, y las consultas CQRS. Skip sin PostgreSQL.
 */
beforeEach(function (): void {
    $this->commands = app(CommandBus::class);
    $this->queries = app(QueryBus::class);
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

/**
 * @return array{0: string, 1: string} par (orderId, invoiceId)
 */
function invoicingIssueViaBus(CommandBus $bus, ?string $orderId = null): array
{
    $orderId ??= Uuid::generate()->value;

    $bus->dispatch(new IssueInvoiceForOrder(
        orderId: $orderId,
        customerName: 'Dismac S.A.',
        customerTaxId: '1023456789',
        currency: 'BOB',
        lines: [
            ['description' => 'Heladera · SKU-RD-1', 'quantity' => 1, 'unit_price_cents' => 100000],
            ['description' => 'Horno · SKU-HR-2', 'quantity' => 1, 'unit_price_cents' => 13000],
        ],
    ));

    /** @var InvoiceRecord $record */
    $record = InvoiceRecord::query()->where('order_id', $orderId)->firstOrFail();

    return [$orderId, (string) $record->getKey()];
}

it('emite la factura de una orden con el stub: queda issued con número y totales calculados', function (): void {
    [$orderId, $id] = invoicingIssueViaBus($this->commands);

    // Bruto 113000 → neto 100000 / iva 13000. El stub asigna un número; la factura queda issued.
    $this->assertDatabaseHas('commerce_invoices', [
        'id' => $id,
        'order_id' => $orderId,
        'currency' => 'BOB',
        'subtotal_cents' => 100000,
        'tax_cents' => 13000,
        'total_cents' => 113000,
        'status' => 'issued',
    ]);

    $record = InvoiceRecord::query()->findOrFail($id);
    expect($record->number)->not->toBeNull()
        ->and($record->issued_at)->not->toBeNull();

    expect(DB::table('outbox_messages')->where('event_name', 'invoicing.invoice_issued')->count())
        ->toBeGreaterThanOrEqual(1);

    /** @var InvoiceView|null $view */
    $view = $this->queries->ask(new GetInvoice(invoiceId: $id));
    expect($view)->not->toBeNull()
        ->and($view->toArray()['net_cents'])->toBe(100000)
        ->and($view->toArray()['iva_cents'])->toBe(13000)
        ->and($view->toArray()['total_cents'])->toBe(113000)
        ->and($view->toArray()['status'])->toBe('issued')
        ->and($view->toArray()['lines'])->toHaveCount(2);

    /** @var InvoiceView|null $byOrder */
    $byOrder = $this->queries->ask(new GetInvoiceByOrder(orderId: $orderId));
    expect($byOrder)->not->toBeNull()
        ->and($byOrder->id)->toBe($id);
});

it('es idempotente por orden: emitir dos veces no crea una segunda factura', function (): void {
    $orderId = Uuid::generate()->value;

    invoicingIssueViaBus($this->commands, $orderId);
    invoicingIssueViaBus($this->commands, $orderId);

    expect(InvoiceRecord::query()->where('order_id', $orderId)->count())->toBe(1);
});

it('lista y filtra por estado', function (): void {
    // Emite una factura (efecto necesario); el id no se usa en este test de listado/filtro.
    invoicingIssueViaBus($this->commands);

    /** @var list<InvoiceView> $issued */
    $issued = $this->queries->ask(new ListInvoices(status: 'issued'));
    expect($issued)->not->toBeEmpty();

    /** @var list<InvoiceView> $drafts */
    $drafts = $this->queries->ask(new ListInvoices(status: 'draft'));
    expect($drafts)->toBeEmpty();
});

it('anula una factura emitida', function (): void {
    [, $id] = invoicingIssueViaBus($this->commands);

    $this->commands->dispatch(new CancelInvoice(invoiceId: $id));

    $this->assertDatabaseHas('commerce_invoices', ['id' => $id, 'status' => 'cancelled']);
    expect(DB::table('outbox_messages')->where('event_name', 'invoicing.invoice_cancelled')->count())
        ->toBeGreaterThanOrEqual(1);
});

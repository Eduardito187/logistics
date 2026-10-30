<?php

declare(strict_types=1);

use Commerce\Invoicing\Domain\Event\InvoiceCancelled;
use Commerce\Invoicing\Domain\Event\InvoiceDrafted;
use Commerce\Invoicing\Domain\Event\InvoiceIssued;
use Commerce\Invoicing\Domain\Exception\InvoicingException;
use Commerce\Invoicing\Domain\Model\Invoice;
use Commerce\Invoicing\Domain\ValueObject\InvoiceId;
use Commerce\Invoicing\Domain\ValueObject\InvoiceLine;
use Commerce\Invoicing\Domain\ValueObject\InvoiceStatus;
use Logistics\Shared\Domain\Clock\Clock;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Reloj fijo para controlar el `issuedAt` del dominio en los tests (prefijo de package para no chocar
 * con helpers top-level de otros tests que Pest carga en el mismo proceso).
 */
function invoicingFixedClock(string $at = '2026-10-29T12:00:00+00:00'): Clock
{
    return new class($at) implements Clock
    {
        public function __construct(private readonly string $at) {}

        public function now(): DateTimeImmutable
        {
            return new DateTimeImmutable($this->at);
        }
    };
}

/**
 * @param list<InvoiceLine>|null $lines
 */
function invoicingDraft(?Uuid $orderId = null, ?array $lines = null): Invoice
{
    return Invoice::draft(
        InvoiceId::generate(),
        $orderId ?? Uuid::generate(),
        'Dismac S.A.',
        '1023456789',
        'BOB',
        $lines ?? [
            InvoiceLine::of('Heladera · SKU-RD-1', 1, 100000),
            InvoiceLine::of('Horno · SKU-HR-2', 1, 13000),
        ],
    );
}

it('calcula neto/iva/total desde las líneas y arranca en draft grabando el evento', function (): void {
    $orderId = Uuid::generate();
    // Bruto = 100000 + 13000 = 113000 → neto 100000 / iva 13000.
    $invoice = invoicingDraft($orderId);

    expect($invoice->status())->toBe(InvoiceStatus::Draft)
        ->and($invoice->number())->toBeNull()
        ->and($invoice->issuedAt())->toBeNull()
        ->and($invoice->totalCents())->toBe(113000)
        ->and($invoice->subtotalCents())->toBe(100000)
        ->and($invoice->taxCents())->toBe(13000)
        ->and($invoice->currency())->toBe('BOB')
        ->and($invoice->orderId()->value)->toBe($orderId->value)
        ->and($invoice->lines())->toHaveCount(2);

    $events = $invoice->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(InvoiceDrafted::class)
        ->and($events[0]->eventName())->toBe('invoicing.invoice_drafted')
        ->and($events[0]->payload()['total_cents'])->toBe(113000);
});

it('la línea congela su total como precio unitario por cantidad', function (): void {
    $line = InvoiceLine::of('Cable · SKU-CB-9', 3, 1500);

    expect($line->lineTotalCents)->toBe(4500);
});

it('emite asignando número, pasa a issued con issuedAt y graba invoicing.invoice_issued', function (): void {
    $orderId = Uuid::generate();
    $invoice = invoicingDraft($orderId);
    $invoice->releaseEvents();

    $invoice->issue('FAC-20261029-ABCD1234', invoicingFixedClock());

    expect($invoice->status())->toBe(InvoiceStatus::Issued)
        ->and($invoice->number())->toBe('FAC-20261029-ABCD1234')
        ->and($invoice->issuedAt())->not->toBeNull();

    $events = $invoice->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(InvoiceIssued::class)
        ->and($events[0]->eventName())->toBe('invoicing.invoice_issued')
        ->and($events[0]->payload()['number'])->toBe('FAC-20261029-ABCD1234')
        ->and($events[0]->payload()['order_id'])->toBe($orderId->value);
});

it('emitir dos veces es idempotente (no vuelve a grabar)', function (): void {
    $invoice = invoicingDraft();
    $invoice->releaseEvents();

    $invoice->issue('FAC-1', invoicingFixedClock());
    $invoice->releaseEvents();
    $invoice->issue('FAC-2', invoicingFixedClock());

    expect($invoice->number())->toBe('FAC-1')
        ->and($invoice->releaseEvents())->toBe([]);
});

it('cancela grabando invoicing.invoice_cancelled', function (): void {
    $invoice = invoicingDraft();
    $invoice->releaseEvents();

    $invoice->cancel();

    expect($invoice->status())->toBe(InvoiceStatus::Cancelled);
    $events = $invoice->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(InvoiceCancelled::class)
        ->and($events[0]->eventName())->toBe('invoicing.invoice_cancelled');
});

it('cancelar dos veces es idempotente', function (): void {
    $invoice = invoicingDraft();
    $invoice->releaseEvents();

    $invoice->cancel();
    $invoice->releaseEvents();
    $invoice->cancel();

    expect($invoice->releaseEvents())->toBe([]);
});

it('no se puede emitir una factura cancelada', function (): void {
    $invoice = invoicingDraft();
    $invoice->cancel();

    $invoice->issue('FAC-X', invoicingFixedClock());
})->toThrow(InvoicingException::class);

it('no se puede crear una factura sin líneas', function (): void {
    Invoice::draft(InvoiceId::generate(), Uuid::generate(), 'Cliente', null, 'BOB', []);
})->toThrow(InvoicingException::class);

it('rechaza un número fiscal vacío al emitir', function (): void {
    $invoice = invoicingDraft();

    $invoice->issue('   ', invoicingFixedClock());
})->toThrow(InvoicingException::class);

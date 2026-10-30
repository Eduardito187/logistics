<?php

declare(strict_types=1);

namespace Commerce\Invoicing\Application\Command\IssueInvoiceForOrder;

use Commerce\Invoicing\Domain\Contract\InvoiceRepository;
use Commerce\Invoicing\Domain\Contract\TaxDocumentGateway;
use Commerce\Invoicing\Domain\Model\Invoice;
use Commerce\Invoicing\Domain\ValueObject\InvoiceLine;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;
use Logistics\Shared\Domain\Clock\Clock;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Crea la factura borrador de una orden, pide el número fiscal al sistema tributario, la marca Issued,
 * la persiste y publica sus eventos al outbox (aggregateType "invoicing.invoice").
 *
 * Idempotente por `orderId`: si ya existe una factura para la orden, no crea otra (el puente
 * orders.order_paid reentrega at-least-once). El save y el append al outbox corren en la misma
 * transacción que abre el decorador transaccional del CommandBus (Infrastructure).
 *
 * @implements CommandHandler<IssueInvoiceForOrder>
 */
final readonly class IssueInvoiceForOrderHandler implements CommandHandler
{
    public function __construct(
        private InvoiceRepository $invoices,
        private TaxDocumentGateway $gateway,
        private Clock $clock,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof IssueInvoiceForOrder);

        $orderId = Uuid::fromString($command->orderId);

        // Dedup por orden: una orden factura a lo sumo una vez. Un reintento del outbox no duplica.
        if ($this->invoices->findByOrderId($orderId) !== null) {
            return;
        }

        $invoice = Invoice::draft(
            $this->invoices->nextIdentity(),
            $orderId,
            $command->customerName,
            $command->customerTaxId,
            $command->currency,
            $this->lines($command),
        );

        $number = $this->gateway->issue(
            $invoice->id(),
            $orderId,
            $invoice->totalCents(),
            $invoice->currency(),
        );
        $invoice->issue($number, $this->clock);

        $this->invoices->save($invoice);

        $this->recordEvents->record($invoice->releaseEvents(), 'invoicing.invoice');
    }

    /**
     * @return list<InvoiceLine>
     */
    private function lines(IssueInvoiceForOrder $command): array
    {
        return array_values(array_map(
            static fn (array $line): InvoiceLine => InvoiceLine::of(
                $line['description'],
                $line['quantity'],
                $line['unit_price_cents'],
            ),
            $command->lines,
        ));
    }
}

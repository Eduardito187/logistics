<?php

declare(strict_types=1);

namespace Commerce\Invoicing\Application\Command\CancelInvoice;

use Commerce\Invoicing\Domain\Contract\InvoiceRepository;
use Commerce\Invoicing\Domain\Exception\InvoicingException;
use Commerce\Invoicing\Domain\ValueObject\InvoiceId;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Resuelve la factura, la anula, la persiste y publica sus eventos al outbox.
 *
 * Es idempotente: `Invoice::cancel()` no vuelve a grabar si ya estaba anulada.
 *
 * @implements CommandHandler<CancelInvoice>
 */
final readonly class CancelInvoiceHandler implements CommandHandler
{
    public function __construct(
        private InvoiceRepository $invoices,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof CancelInvoice);

        $invoice = $this->invoices->findById(InvoiceId::fromString($command->invoiceId));
        if ($invoice === null) {
            throw InvoicingException::because("No existe la factura {$command->invoiceId}.");
        }

        $invoice->cancel();

        $this->invoices->save($invoice);

        $this->recordEvents->record($invoice->releaseEvents(), 'invoicing.invoice');
    }
}

<?php

declare(strict_types=1);

namespace Commerce\Invoicing\Application\Query\GetInvoice;

use Commerce\Invoicing\Application\ReadModel\InvoiceView;
use Commerce\Invoicing\Domain\Contract\InvoiceRepository;
use Commerce\Invoicing\Domain\ValueObject\InvoiceId;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Devuelve una factura por su id, o null si no existe.
 *
 * @implements QueryHandler<GetInvoice, InvoiceView|null>
 */
final readonly class GetInvoiceHandler implements QueryHandler
{
    public function __construct(
        private InvoiceRepository $invoices,
    ) {}

    public function handle(Query $query): ?InvoiceView
    {
        assert($query instanceof GetInvoice);

        $invoice = $this->invoices->findById(InvoiceId::fromString($query->invoiceId));

        return $invoice !== null ? InvoiceView::fromDomain($invoice) : null;
    }
}

<?php

declare(strict_types=1);

namespace Commerce\Invoicing\Application\Query\ListInvoices;

use Commerce\Invoicing\Application\ReadModel\InvoiceView;
use Commerce\Invoicing\Domain\Contract\InvoiceRepository;
use Commerce\Invoicing\Domain\Model\Invoice;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Devuelve la lista de facturas como proyecciones de lectura, con filtro opcional por estado.
 *
 * @implements QueryHandler<ListInvoices, list<InvoiceView>>
 */
final readonly class ListInvoicesHandler implements QueryHandler
{
    public function __construct(
        private InvoiceRepository $invoices,
    ) {}

    /**
     * @return list<InvoiceView>
     */
    public function handle(Query $query): array
    {
        assert($query instanceof ListInvoices);

        $invoices = $this->invoices->all();
        if ($query->status !== null) {
            $invoices = array_values(array_filter(
                $invoices,
                static fn (Invoice $invoice): bool => $invoice->status()->value === $query->status,
            ));
        }

        return array_map(static fn (Invoice $invoice): InvoiceView => InvoiceView::fromDomain($invoice), $invoices);
    }
}

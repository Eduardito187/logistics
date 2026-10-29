<?php

declare(strict_types=1);

namespace Commerce\Invoicing\Application\Query\GetInvoiceByOrder;

use Commerce\Invoicing\Application\ReadModel\InvoiceView;
use Commerce\Invoicing\Domain\Contract\InvoiceRepository;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Devuelve la factura de una orden, o null si no hay ninguna.
 *
 * @implements QueryHandler<GetInvoiceByOrder, InvoiceView|null>
 */
final readonly class GetInvoiceByOrderHandler implements QueryHandler
{
    public function __construct(
        private InvoiceRepository $invoices,
    ) {}

    public function handle(Query $query): ?InvoiceView
    {
        assert($query instanceof GetInvoiceByOrder);

        $invoice = $this->invoices->findByOrderId(Uuid::fromString($query->orderId));

        return $invoice !== null ? InvoiceView::fromDomain($invoice) : null;
    }
}

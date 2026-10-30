<?php

declare(strict_types=1);

namespace Commerce\Invoicing\Presentation\Http\Controller;

use Commerce\Invoicing\Application\Query\GetInvoiceByOrder\GetInvoiceByOrder;
use Commerce\Invoicing\Application\ReadModel\InvoiceView;
use Commerce\Invoicing\Presentation\Http\ApiController;
use Commerce\Invoicing\Presentation\Http\Request\GetInvoiceByOrderRequest;
use Illuminate\Http\JsonResponse;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Devuelve la factura asociada a una orden (consulta GetInvoiceByOrder). Responde 404 si no existe.
 */
final class GetInvoiceByOrderController extends ApiController
{
    public function __construct(
        private readonly QueryBus $queries,
    ) {}

    public function __invoke(GetInvoiceByOrderRequest $request): JsonResponse
    {
        return $this->guard(function () use ($request): JsonResponse {
            /** @var InvoiceView|null $invoice */
            $invoice = $this->queries->ask(new GetInvoiceByOrder(orderId: $request->orderId()));

            if ($invoice === null) {
                return $this->error('invoice_not_found', 'No invoice found for the given order.', 404);
            }

            return $this->data($invoice->toArray());
        });
    }
}

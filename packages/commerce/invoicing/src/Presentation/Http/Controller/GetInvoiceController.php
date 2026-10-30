<?php

declare(strict_types=1);

namespace Commerce\Invoicing\Presentation\Http\Controller;

use Commerce\Invoicing\Application\Query\GetInvoice\GetInvoice;
use Commerce\Invoicing\Application\ReadModel\InvoiceView;
use Commerce\Invoicing\Presentation\Http\ApiController;
use Commerce\Invoicing\Presentation\Http\Request\GetInvoiceRequest;
use Illuminate\Http\JsonResponse;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Devuelve una factura por su id (consulta GetInvoice). Responde 404 si no existe.
 */
final class GetInvoiceController extends ApiController
{
    public function __construct(
        private readonly QueryBus $queries,
    ) {}

    public function __invoke(GetInvoiceRequest $request): JsonResponse
    {
        return $this->guard(function () use ($request): JsonResponse {
            /** @var InvoiceView|null $invoice */
            $invoice = $this->queries->ask(new GetInvoice(invoiceId: $request->invoiceId()));

            if ($invoice === null) {
                return $this->error('invoice_not_found', 'No invoice found for the given identifier.', 404);
            }

            return $this->data($invoice->toArray());
        });
    }
}

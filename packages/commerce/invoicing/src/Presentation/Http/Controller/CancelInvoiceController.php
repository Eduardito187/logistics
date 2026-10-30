<?php

declare(strict_types=1);

namespace Commerce\Invoicing\Presentation\Http\Controller;

use Commerce\Invoicing\Presentation\Http\ApiController;
use Commerce\Invoicing\Presentation\Http\Request\CancelInvoiceRequest;
use Illuminate\Http\JsonResponse;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Puerta integrations: despacha CancelInvoice al bus CQRS.
 *
 * El id viene por la ruta; no hay cuerpo. El bus no devuelve valor (escritura transacción + outbox): la
 * respuesta es un 202 de acuse. Las violaciones de regla de dominio (factura inexistente) las traduce
 * guard() a 422.
 */
final class CancelInvoiceController extends ApiController
{
    public function __construct(
        private readonly CommandBus $commands,
    ) {}

    public function __invoke(CancelInvoiceRequest $request): JsonResponse
    {
        return $this->guard(function () use ($request): JsonResponse {
            $command = $request->toCommand();

            $this->commands->dispatch($command);

            return $this->accepted(['invoice_id' => $command->invoiceId]);
        });
    }
}

<?php

declare(strict_types=1);

namespace Commerce\Invoicing\Presentation\Http\Controller;

use Commerce\Invoicing\Application\Query\GetInvoice\GetInvoice;
use Commerce\Invoicing\Application\ReadModel\InvoiceView;
use Commerce\Invoicing\Presentation\Http\Request\GetInvoiceRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Response;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Renderiza el DOCUMENTO interno de la factura como HTML imprimible (text/html, no PDF).
 *
 * Es el documento fiscal INTERNO de la plataforma: emisor, cliente, líneas y el desglose Neto / IVA (13 %
 * por dentro) / Total, a partir del read model `InvoiceView`. Sin recursos externos ni CDN (estilos
 * inline). El cliente lo imprime desde el navegador.
 *
 * IMPORTANTE — PENDIENTE EXTERNO: la EMISIÓN FISCAL REAL ante el SIN de Bolivia (facturación
 * electrónica: CUF/CUFD, firma digital, código de control y envío al servicio del Servicio de Impuestos
 * Nacionales) requiere una integración externa con credenciales del SIN que NO están disponibles. Este
 * documento es el comprobante interno; la emisión fiscal válida queda fuera del alcance de este código y
 * se resolverá por el puerto `TaxDocumentGateway` cuando exista el conector del SIN.
 *
 * Presentation habla con Application vía el QueryBus; nunca toca Infrastructure ni el dominio.
 */
final class RenderInvoiceDocumentController
{
    public function __construct(
        private readonly QueryBus $queries,
    ) {}

    public function __invoke(GetInvoiceRequest $request): View | Response
    {
        $invoice = $this->queries->ask(new GetInvoice(invoiceId: $request->invoiceId()));

        if (!$invoice instanceof InvoiceView) {
            return new Response('No invoice found for the given identifier.', 404);
        }

        // Datos del emisor: configuración de presentación del documento (no es dominio). En producción se
        // fijan por env; el default es un placeholder sobrio.
        $issuer = config('commerce_invoicing.issuer');

        return view('commerce-invoicing::document', [
            'invoice' => $invoice->toArray(),
            'issuer' => is_array($issuer) ? $issuer : [],
        ]);
    }
}

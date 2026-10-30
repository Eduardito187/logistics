<?php

declare(strict_types=1);

return [
    // Adaptador del sistema tributario que emite el documento fiscal (clave de self::GATEWAYS del
    // InvoicingServiceProvider). Default: stub, que genera un número sintético — coherente con "100%
    // open-source, sin servicios de pago" (ADR 013/014/015). En producción se fija por env
    // INVOICING_GATEWAY (p. ej. el conector de facturación electrónica del SIN).
    'gateway' => env('INVOICING_GATEWAY', 'stub'),

    // Datos del emisor que encabezan el DOCUMENTO interno de la factura (render HTML, EC-F0-10). Son
    // configuración de presentación, no dominio. El default es un placeholder sobrio; en producción se
    // fijan por env. NOTA: la emisión fiscal válida ante el SIN (CUF/CUFD, firma) es un pendiente externo
    // que requiere credenciales del SIN y se resolverá por el puerto TaxDocumentGateway.
    'issuer' => [
        'business_name' => env('INVOICING_ISSUER_NAME', 'DISMAC S.A.'),
        'nit' => env('INVOICING_ISSUER_NIT', '1000000000'),
        'address' => env('INVOICING_ISSUER_ADDRESS', 'Santa Cruz de la Sierra, Bolivia'),
    ],
];

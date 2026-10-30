<?php

declare(strict_types=1);

return [
    // Adaptador del sistema tributario que emite el documento fiscal (clave de self::GATEWAYS del
    // InvoicingServiceProvider). Default: stub, que genera un número sintético — coherente con "100%
    // open-source, sin servicios de pago" (ADR 013/014/015). En producción se fija por env
    // INVOICING_GATEWAY (p. ej. el conector de facturación electrónica del SIN).
    'gateway' => env('INVOICING_GATEWAY', 'stub'),
];

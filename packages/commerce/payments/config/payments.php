<?php

declare(strict_types=1);

return [
    // Pasarela de pago activa (clave de self::GATEWAYS del PaymentsServiceProvider). Default: stub, que
    // aprueba siempre — coherente con "100% open-source, sin servicios de pago" (ADR 013/014/015). En
    // producción se fija por env PAYMENTS_GATEWAY (p. ej. qr_bcb).
    'gateway' => env('PAYMENTS_GATEWAY', 'stub'),
];

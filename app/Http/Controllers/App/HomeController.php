<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Landing PÚBLICO de la app de cliente (Vue+Inertia): la cara del servicio. Desde acá el cliente ingresa
 * el código de su enlace de seguimiento y llega a «Seguí tu pedido». Sin auth ni PII. Un portal con
 * historial completo por cliente requeriría cuentas de cliente (fuera de alcance de esta ola).
 */
final class HomeController
{
    public function __invoke(): InertiaResponse
    {
        return Inertia::render('Home');
    }
}

<?php

declare(strict_types=1);

namespace Commerce\Payments\Domain\Contract;

use Commerce\Payments\Domain\ValueObject\PaymentIntent;
use Commerce\Payments\Domain\ValueObject\PaymentResult;

/**
 * Puerto de pasarela de pago: el contrato detrás del cual se enchufan los proveedores.
 *
 * El dominio solo conoce esta interfaz; la implementación (stub, QR BCB, etc.) vive en Infrastructure y
 * actúa como anti-corruption layer del proveedor. `charge` intenta el cobro de una intención y devuelve
 * su resultado (aprobado con referencia, o rechazado con motivo). Nunca lanza HTTP síncrono del dominio.
 */
interface PaymentGateway
{
    public function charge(PaymentIntent $intent): PaymentResult;
}

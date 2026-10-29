<?php

declare(strict_types=1);

namespace Commerce\Payments\Infrastructure\Gateway;

use Commerce\Payments\Domain\Contract\PaymentGateway;
use Commerce\Payments\Domain\ValueObject\PaymentIntent;
use Commerce\Payments\Domain\ValueObject\PaymentResult;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Pasarela stub: SIEMPRE aprueba y genera una referencia sintética. Para dev/tests.
 *
 * Es el default del contexto, coherente con el stack 100% open-source y sin servicios de pago
 * (ADR 013/014/015). Nunca hace I/O externo: solo fabrica un resultado aprobado determinista por intención.
 * El binding por env (ver PaymentsServiceProvider) permite enchufar una pasarela real sin tocar el dominio.
 */
final class StubPaymentGateway implements PaymentGateway
{
    public function charge(PaymentIntent $intent): PaymentResult
    {
        return PaymentResult::approved($this->syntheticRef($intent));
    }

    /**
     * Referencia sintética legible y única por intento (no es un dato real de proveedor).
     */
    private function syntheticRef(PaymentIntent $intent): string
    {
        $suffix = strtoupper(substr(str_replace('-', '', Uuid::generate()->value), 0, 12));

        return 'STUB-'.strtoupper($intent->method->value).'-'.$suffix;
    }
}

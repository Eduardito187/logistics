<?php

declare(strict_types=1);

namespace Commerce\Invoicing\Infrastructure\Gateway;

use Commerce\Invoicing\Domain\Contract\TaxDocumentGateway;
use Commerce\Invoicing\Domain\ValueObject\InvoiceId;
use Logistics\Shared\Domain\Clock\Clock;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Adaptador stub del sistema tributario: genera un número fiscal sintético y único. Para dev/tests.
 *
 * Es el default del contexto, coherente con el stack 100% open-source y sin servicios de pago
 * (ADR 013/014/015). Nunca hace I/O externo: solo fabrica un número con la forma `FAC-<timestamp>-<hex>`.
 * El binding por config (ver InvoicingServiceProvider) permite enchufar el conector real de facturación
 * electrónica del SIN sin tocar el dominio.
 */
final readonly class StubTaxDocumentGateway implements TaxDocumentGateway
{
    public function __construct(
        private Clock $clock,
    ) {}

    public function issue(InvoiceId $id, Uuid $orderId, int $totalCents, string $currency): string
    {
        $timestamp = $this->clock->now()->format('YmdHis');
        $hex = strtoupper(substr(str_replace('-', '', Uuid::generate()->value), 0, 8));

        return 'FAC-'.$timestamp.'-'.$hex;
    }
}

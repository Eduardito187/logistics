<?php

declare(strict_types=1);

namespace Commerce\Invoicing\Domain\Contract;

use Commerce\Invoicing\Domain\ValueObject\InvoiceId;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Puerto del sistema tributario: el contrato detrás del cual se enchufan los emisores de documento fiscal.
 *
 * El dominio solo conoce esta interfaz; la implementación (stub, conector de facturación electrónica del
 * SIN, etc.) vive en Infrastructure y actúa como anti-corruption layer del proveedor. `issue` solicita la
 * emisión del documento y devuelve el **número fiscal** asignado. Nunca lanza HTTP síncrono del dominio.
 */
interface TaxDocumentGateway
{
    /**
     * Emite el documento fiscal y devuelve el número asignado por el sistema tributario.
     */
    public function issue(InvoiceId $id, Uuid $orderId, int $totalCents, string $currency): string;
}

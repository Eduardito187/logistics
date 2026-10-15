<?php

declare(strict_types=1);

namespace Logistics\Carriers\Application\Command\AttachSettlementDocument;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de adjuntar un documento (factura, comprobante) a una liquidación.
 */
final readonly class AttachSettlementDocument implements Command
{
    public function __construct(
        public string $settlementId,
        public string $documentType,
        public string $documentReference,
    ) {}
}

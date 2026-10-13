<?php

declare(strict_types=1);

namespace Logistics\Carriers\Application\Command\RegisterCarrier;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de registrar un transportista tercero nuevo.
 *
 * Los documentos llegan como pares simples {type, reference} para no acoplar la entrada al dominio.
 */
final readonly class RegisterCarrier implements Command
{
    /**
     * @param list<array{type: string, reference: string}> $documents
     */
    public function __construct(
        public string $name,
        public string $taxId,
        public array $documents = [],
    ) {}
}

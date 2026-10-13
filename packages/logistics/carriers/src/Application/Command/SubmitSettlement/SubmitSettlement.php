<?php

declare(strict_types=1);

namespace Logistics\Carriers\Application\Command\SubmitSettlement;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de un transportista de enviar una liquidación por un corredor y período.
 *
 * El corredor llega como sus tres primitivos, el monto como centavos + moneda y el período como texto
 * `YYYY-MM`, para no acoplar la entrada a los VO del dominio; el handler los convierte.
 */
final readonly class SubmitSettlement implements Command
{
    /**
     * @param list<array{type: string, reference: string}> $documents
     */
    public function __construct(
        public string $carrierId,
        public string $corridorFrom,
        public string $corridorTo,
        public string $corridorType,
        public string $period,
        public int $amountCents,
        public string $currency,
        public string $reference,
        public array $documents = [],
    ) {}
}

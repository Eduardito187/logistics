<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\Command\RecordExpense;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de registrar un gasto de flota contra un vehículo.
 *
 * La categoría llega como su valor primitivo y el importe como centavos + moneda, para no acoplar la
 * entrada a los VO del dominio; el handler los convierte. `incurredAt` es una fecha/hora ISO 8601.
 */
final readonly class RecordExpense implements Command
{
    public function __construct(
        public string $vehicleId,
        public string $category,
        public int $amountCents,
        public string $currency,
        public string $incurredAt,
        public ?string $description = null,
        public ?string $note = null,
    ) {}
}

<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\ReadModel;

use DateTimeImmutable;
use Logistics\FleetOps\Domain\Model\Expense;

/**
 * Proyección de lectura de un gasto de flota para listados y API. Primitivos: no expone objetos de dominio.
 * El importe se expone como centavos + moneda.
 */
final readonly class ExpenseView
{
    private function __construct(
        public string $id,
        public string $vehicleId,
        public string $category,
        public int $amountCents,
        public string $currency,
        public ?string $description,
        public string $incurredAt,
        public ?string $note,
    ) {}

    public static function fromDomain(Expense $expense): self
    {
        return new self(
            $expense->id()->toString(),
            $expense->vehicleId(),
            $expense->category()->value,
            $expense->amount()->cents,
            $expense->amount()->currency,
            $expense->description(),
            $expense->incurredAt()->format(DateTimeImmutable::ATOM),
            $expense->note(),
        );
    }

    /**
     * @return array{id: string, vehicle_id: string, category: string, amount_cents: int, currency: string, description: string|null, incurred_at: string, note: string|null}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'vehicle_id' => $this->vehicleId,
            'category' => $this->category,
            'amount_cents' => $this->amountCents,
            'currency' => $this->currency,
            'description' => $this->description,
            'incurred_at' => $this->incurredAt,
            'note' => $this->note,
        ];
    }
}

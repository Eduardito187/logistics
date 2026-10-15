<?php

declare(strict_types=1);

namespace Logistics\Notifications\Application\ReadModel;

use Logistics\Notifications\Domain\ValueObject\SmsBudget;

/**
 * Modelo de lectura del presupuesto mensual de SMS: consumo, tope y cupo restante del período.
 */
final readonly class SmsBudgetView
{
    public function __construct(
        public string $period,
        public int $sent,
        public int $cap,
        public int $remaining,
    ) {}

    public static function fromBudget(SmsBudget $budget): self
    {
        return new self($budget->period, $budget->sent, $budget->cap, $budget->remaining());
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'period' => $this->period,
            'sent' => $this->sent,
            'cap' => $this->cap,
            'remaining' => $this->remaining,
        ];
    }
}

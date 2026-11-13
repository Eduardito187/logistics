<?php

declare(strict_types=1);

namespace Logistics\Compensation\Domain\ValueObject;

/**
 * Traza del cálculo del vale: cada paso y cada tope aplicado, para la auditoría y para mostrarlo legible en
 * el admin ("Base Bs 1 049,70 · 15 % = Bs 157,45 · tope 50 % no aplica · redondeo Bs 1 → Bs 157").
 *
 * Es solo datos (lista de pasos con etiqueta y monto en centavos); no tiene lógica de cálculo.
 */
final readonly class CalculationTrace
{
    /**
     * @param list<array{label: string, cents: int}> $steps
     */
    private function __construct(public array $steps) {}

    public static function empty(): self
    {
        return new self([]);
    }

    public function with(string $label, Money $amount): self
    {
        return new self([...$this->steps, ['label' => $label, 'cents' => $amount->cents]]);
    }

    /**
     * @return list<array{label: string, cents: int}>
     */
    public function toArray(): array
    {
        return $this->steps;
    }
}

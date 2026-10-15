<?php

declare(strict_types=1);

namespace Logistics\Notifications\Domain\ValueObject;

use Logistics\Notifications\Domain\Exception\NotificationsException;

/**
 * Presupuesto de SMS de un período (mes `YYYY-MM`): cuántos se enviaron y cuál es el tope configurado.
 *
 * El SMS es el único canal con costo real por mensaje (ADR 006): se usa solo como respaldo crítico y
 * con tope. Este VO es la lectura del consumo; el repositorio es quien consume de forma atómica.
 */
final readonly class SmsBudget
{
    private function __construct(
        public string $period,
        public int $sent,
        public int $cap,
    ) {}

    public static function of(string $period, int $sent, int $cap): self
    {
        $trimmedPeriod = trim($period);
        if (!preg_match('/^\d{4}-\d{2}$/', $trimmedPeriod)) {
            throw NotificationsException::because("el período del presupuesto de SMS debe ser YYYY-MM, no {$period}");
        }
        if ($sent < 0) {
            throw NotificationsException::because('el consumo de SMS no puede ser negativo');
        }
        if ($cap < 0) {
            throw NotificationsException::because('el tope de SMS no puede ser negativo');
        }

        return new self($trimmedPeriod, $sent, $cap);
    }

    /**
     * ¿Queda cupo para enviar un SMS más en el período? Un tope en cero significa SMS deshabilitado.
     */
    public function canSend(): bool
    {
        return $this->sent < $this->cap;
    }

    /**
     * SMS que todavía se pueden enviar en el período (nunca negativo).
     */
    public function remaining(): int
    {
        return max(0, $this->cap - $this->sent);
    }

    /**
     * @return array{period: string, sent: int, cap: int, remaining: int}
     */
    public function toArray(): array
    {
        return [
            'period' => $this->period,
            'sent' => $this->sent,
            'cap' => $this->cap,
            'remaining' => $this->remaining(),
        ];
    }
}

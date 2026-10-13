<?php

declare(strict_types=1);

namespace Logistics\Promise\Domain\ValueObject;

use DateTimeImmutable;
use Logistics\Promise\Domain\Exception\PromiseException;

/**
 * Ventana de validez de una reserva de cupo: hasta cuándo se mantiene reservado el cupo mientras el
 * canal decide si confirma. Pasado ese instante, la reserva puede expirar y el cupo se libera.
 *
 * Es un instante de expiración inmutable. El dominio compara contra un "ahora" que le pasa Application
 * (vía Clock): el VO no lee el reloj global, así los tests fijan el tiempo.
 */
final readonly class ReservationWindow
{
    private function __construct(public DateTimeImmutable $expiresAt) {}

    /**
     * Construye la ventana a partir del instante exacto de expiración.
     */
    public static function until(DateTimeImmutable $expiresAt): self
    {
        return new self($expiresAt);
    }

    /**
     * Construye la ventana como "ahora + N minutos". N debe ser positivo.
     */
    public static function minutesFrom(DateTimeImmutable $now, int $minutes): self
    {
        if ($minutes <= 0) {
            throw PromiseException::because('la ventana de reserva debe durar al menos un minuto');
        }

        return new self($now->modify("+{$minutes} minutes"));
    }

    /**
     * ¿La ventana ya venció respecto del instante dado? En el borde exacto se considera vencida.
     */
    public function isExpiredAt(DateTimeImmutable $now): bool
    {
        return $now >= $this->expiresAt;
    }

    public function equals(self $other): bool
    {
        return $this->expiresAt->getTimestamp() === $other->expiresAt->getTimestamp();
    }
}

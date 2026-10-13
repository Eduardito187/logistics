<?php

declare(strict_types=1);

namespace Logistics\Tracking\Domain\ValueObject;

use DateTimeImmutable;

/**
 * Hora estimada de llegada (ETA) del envío al destino.
 *
 * Es un instante en el tiempo; se expone en la página pública como referencia para el cliente. El
 * dominio pide la hora por el Clock del núcleo, nunca con now() global, así que el ETA siempre se
 * construye desde un DateTimeImmutable ya resuelto afuera.
 */
final readonly class Eta
{
    private function __construct(public DateTimeImmutable $at) {}

    public static function at(DateTimeImmutable $at): self
    {
        return new self($at);
    }

    public function equals(self $other): bool
    {
        return $this->at->getTimestamp() === $other->at->getTimestamp();
    }

    /**
     * Representación ISO 8601 (RFC 3339) para APIs y persistencia.
     */
    public function toIso8601(): string
    {
        return $this->at->format(DateTimeImmutable::ATOM);
    }
}

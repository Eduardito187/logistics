<?php

declare(strict_types=1);

namespace Logistics\Inbound\Domain\ValueObject;

use Logistics\Inbound\Domain\Exception\InboundException;

/**
 * Cantidad recibida de una línea, desglosada por el resultado del control de calidad: aceptada (entra al
 * stock), rechazada (no entra) y dañada (rechazada por daño). Value object inmutable.
 *
 * `accepted` es lo que efectivamente se etiqueta y pasa al punto de convergencia; `rejected` + `damaged`
 * se devuelven al seller (logística inversa, fase posterior). La coherencia con el QcOutcome la valida el
 * ReceiptLine, no este VO.
 */
final readonly class ReceivedQuantity
{
    private function __construct(
        public int $accepted,
        public int $rejected,
        public int $damaged,
    ) {}

    public static function of(int $accepted, int $rejected = 0, int $damaged = 0): self
    {
        if ($accepted < 0 || $rejected < 0 || $damaged < 0) {
            throw InboundException::because('Las cantidades recibidas no pueden ser negativas.');
        }
        if ($accepted + $rejected + $damaged === 0) {
            throw InboundException::because('Una línea recibida debe tener al menos una pieza (aceptada, rechazada o dañada).');
        }

        return new self($accepted, $rejected, $damaged);
    }

    /**
     * Total físico recibido (aceptado + rechazado + dañado).
     */
    public function total(): int
    {
        return $this->accepted + $this->rejected + $this->damaged;
    }

    public function equals(self $other): bool
    {
        return $this->accepted === $other->accepted
            && $this->rejected === $other->rejected
            && $this->damaged === $other->damaged;
    }

    /**
     * @return array{accepted: int, rejected: int, damaged: int}
     */
    public function toArray(): array
    {
        return [
            'accepted' => $this->accepted,
            'rejected' => $this->rejected,
            'damaged' => $this->damaged,
        ];
    }
}

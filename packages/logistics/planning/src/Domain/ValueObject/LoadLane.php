<?php

declare(strict_types=1);

namespace Logistics\Planning\Domain\ValueObject;

use Logistics\Planning\Domain\Exception\PlanningException;

/**
 * Carril de carga dentro del camión: la zona física donde el cargador deja un bulto para que, al
 * descargar en orden inverso de entrega, el primer envío a entregar quede más a mano (ADR del plan de
 * carga).
 *
 * Se representa como una etiqueta corta en mayúsculas ('A', 'B', 'C', …). El cálculo del carril a
 * partir del orden de carga lo hace el agregado; este VO solo valida y transporta la etiqueta.
 */
final readonly class LoadLane
{
    private const MAX_LENGTH = 8;

    private function __construct(public string $label) {}

    public static function of(string $label): self
    {
        $normalized = strtoupper(trim($label));
        if ($normalized === '') {
            throw PlanningException::because('el carril de carga no puede estar vacío');
        }
        if (mb_strlen($normalized) > self::MAX_LENGTH) {
            throw PlanningException::because('el carril de carga excede '.self::MAX_LENGTH.' caracteres');
        }

        return new self($normalized);
    }

    public function equals(self $other): bool
    {
        return $this->label === $other->label;
    }

    public function __toString(): string
    {
        return $this->label;
    }
}

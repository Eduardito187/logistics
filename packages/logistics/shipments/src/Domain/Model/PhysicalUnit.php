<?php

declare(strict_types=1);

namespace Logistics\Shipments\Domain\Model;

use Logistics\Shared\Domain\ValueObject\Ownership;
use Logistics\Shipments\Domain\ValueObject\Fraction;
use Logistics\Shipments\Domain\ValueObject\PhysicalUnitId;
use Logistics\Shipments\Domain\ValueObject\ShipmentLineId;

/**
 * Unidad física (bulto): la pieza real que se etiqueta, carga y descarga. Hay un bulto por unidad de
 * producto de cada línea.
 *
 * - `unit`  = "unidad N de M" del producto de la línea (p. ej. el ropero 1 de 2).
 * - `piece` = "pieza N de M" cuando una unidad viene en varias cajas (por defecto 1 de 1).
 *
 * Sin estos dos datos no se puede detectar que se cargaron 2 de las 3 cajas de un mismo ropero.
 */
final class PhysicalUnit
{
    private function __construct(
        private readonly PhysicalUnitId $id,
        private readonly ShipmentLineId $lineId,
        private readonly Fraction $unit,
        private readonly Fraction $piece,
        private readonly Ownership $ownership,
    ) {}

    /**
     * Crea un bulto. La propiedad (`ownership`) es opcional y por defecto es Dismac (retail, `OWNED`),
     * así toda construcción existente sigue funcionando sin cambios. El contexto `inbound` pasará aquí
     * la propiedad del seller cuando etiquete unidades recibidas (ADR 017).
     */
    public static function create(
        PhysicalUnitId $id,
        ShipmentLineId $lineId,
        Fraction $unit,
        Fraction $piece,
        ?Ownership $ownership = null,
    ): self {
        return new self($id, $lineId, $unit, $piece, $ownership ?? Ownership::dismacOwned());
    }

    public function id(): PhysicalUnitId
    {
        return $this->id;
    }

    public function lineId(): ShipmentLineId
    {
        return $this->lineId;
    }

    public function unit(): Fraction
    {
        return $this->unit;
    }

    public function piece(): Fraction
    {
        return $this->piece;
    }

    /**
     * Propiedad del bulto: a quién pertenece y bajo qué modo de fulfillment. Por defecto Dismac (retail).
     */
    public function ownership(): Ownership
    {
        return $this->ownership;
    }
}

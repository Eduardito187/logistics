<?php

declare(strict_types=1);

namespace Logistics\Planning\Domain\Model;

use Logistics\Planning\Domain\ValueObject\Eta;
use Logistics\Planning\Domain\ValueObject\LoadLane;
use Logistics\Planning\Domain\ValueObject\PlannedStopId;
use Logistics\Planning\Domain\ValueObject\StopSequence;

/**
 * Parada del plan: la entrega de un envío en una posición de la secuencia de reparto.
 *
 * Entidad del agregado DeliveryPlan: tiene identidad propia pero solo vive dentro del plan que la
 * contiene. Referencia al envío SOLO por id string (no se acopla al contexto de envíos). La secuencia,
 * la ETA y el carril los fija el agregado al proponer o recomputar; por eso son mutables y, hasta la
 * propuesta, ETA y carril pueden estar vacíos.
 */
final class PlannedStop
{
    private function __construct(
        private readonly PlannedStopId $id,
        private readonly string $shipmentId,
        private StopSequence $sequence,
        private ?Eta $eta,
        private ?LoadLane $lane,
    ) {}

    /**
     * Crea una parada recién asignada: con posición, todavía sin ETA ni carril.
     */
    public static function assign(PlannedStopId $id, string $shipmentId, StopSequence $sequence): self
    {
        return new self($id, $shipmentId, $sequence, null, null);
    }

    /**
     * Reconstituye una parada desde la persistencia, con todos sus datos ya resueltos (o vacíos).
     */
    public static function reconstitute(
        PlannedStopId $id,
        string $shipmentId,
        StopSequence $sequence,
        ?Eta $eta,
        ?LoadLane $lane,
    ): self {
        return new self($id, $shipmentId, $sequence, $eta, $lane);
    }

    /**
     * Fija la posición de la parada en la secuencia de reparto. Solo lo llama el agregado.
     */
    public function placeAt(StopSequence $sequence): void
    {
        $this->sequence = $sequence;
    }

    /**
     * Fija la ETA calculada por el motor de rutas. Solo lo llama el agregado.
     */
    public function arriveAt(Eta $eta): void
    {
        $this->eta = $eta;
    }

    /**
     * Asigna el carril de carga. Solo lo llama el agregado al recomputar el plan de carga.
     */
    public function loadInto(LoadLane $lane): void
    {
        $this->lane = $lane;
    }

    public function id(): PlannedStopId
    {
        return $this->id;
    }

    public function shipmentId(): string
    {
        return $this->shipmentId;
    }

    public function sequence(): StopSequence
    {
        return $this->sequence;
    }

    public function eta(): ?Eta
    {
        return $this->eta;
    }

    public function lane(): ?LoadLane
    {
        return $this->lane;
    }
}

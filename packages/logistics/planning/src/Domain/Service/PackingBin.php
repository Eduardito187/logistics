<?php

declare(strict_types=1);

namespace Logistics\Planning\Domain\Service;

use Logistics\Planning\Domain\ValueObject\DeliveryCandidate;

/**
 * Contenedor mutable de empaque: un camión mientras se le acomodan pedidos. Vive solo dentro del
 * DailyPlanComposer (es su cuaderno de trabajo), por eso es mutable y no un value object inmutable.
 *
 * Arranca con la capacidad UTILIZABLE del camión (nominal menos la holgura) y sin departamento ni
 * franja ligados. En cuanto recibe el primer pedido queda LIGADO a ese departamento (y, en modo slots,
 * a esa franja): no vuelve a aceptar pedidos de otro departamento ni de otra franja. Así se garantiza
 * la invariante "un camión entrega a un solo departamento".
 */
final class PackingBin
{
    private ?string $boundDepartmentId = null;

    private bool $bound = false;

    private ?string $boundSlotKey = null;

    private float $usedKg = 0.0;

    private float $usedM3 = 0.0;

    /** @var list<DeliveryCandidate> */
    private array $candidates = [];

    public function __construct(
        private readonly string $vehicleId,
        private readonly ?string $vehicleDepartmentId,
        private readonly float $usableKg,
        private readonly float $usableM3,
    ) {}

    /**
     * ¿Puede este bin tomar un pedido del departamento/franja dados sin violar las invariantes?
     *
     * Reglas:
     * - El departamento debe calzar: si ya está ligado, al mismo; si no, un camión flexible (sin
     *   departamento propio) acepta cualquiera, y uno con departamento propio solo el suyo.
     * - En modo slots, la franja debe calzar igual que el departamento (null = sin restricción de franja).
     * - Debe quedar capacidad en kg y m3.
     */
    public function accepts(DeliveryCandidate $candidate, ?string $slotKey): bool
    {
        if (!$this->departmentFits($candidate->departmentId)) {
            return false;
        }
        if ($slotKey !== null && $this->bound && $this->boundSlotKey !== $slotKey) {
            return false;
        }

        return $this->usedKg + $candidate->weightKg <= $this->usableKg
            && $this->usedM3 + $candidate->volumeM3 <= $this->usableM3;
    }

    /**
     * Acomoda el pedido y, si es el primero, liga el bin a su departamento y franja.
     */
    public function place(DeliveryCandidate $candidate, ?string $slotKey): void
    {
        if (!$this->bound) {
            $this->bound = true;
            $this->boundDepartmentId = $candidate->departmentId;
            $this->boundSlotKey = $slotKey;
        }

        $this->usedKg += $candidate->weightKg;
        $this->usedM3 += $candidate->volumeM3;
        $this->candidates[] = $candidate;
    }

    public function isEmpty(): bool
    {
        return $this->candidates === [];
    }

    public function vehicleId(): string
    {
        return $this->vehicleId;
    }

    public function departmentId(): ?string
    {
        return $this->boundDepartmentId;
    }

    public function usedKg(): float
    {
        return $this->usedKg;
    }

    public function usedM3(): float
    {
        return $this->usedM3;
    }

    /**
     * @return list<DeliveryCandidate>
     */
    public function candidates(): array
    {
        return $this->candidates;
    }

    /**
     * El departamento del pedido calza con el estado del bin.
     */
    private function departmentFits(?string $candidateDepartmentId): bool
    {
        if ($this->bound) {
            return $this->boundDepartmentId === $candidateDepartmentId;
        }

        // Aún libre: un camión flexible (sin departamento) acepta cualquiera; uno con departamento
        // propio, solo pedidos de su mismo departamento (y nunca pedidos sin departamento resuelto).
        if ($this->vehicleDepartmentId === null) {
            return true;
        }

        return $this->vehicleDepartmentId === $candidateDepartmentId;
    }
}

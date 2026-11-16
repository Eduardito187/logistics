<?php

declare(strict_types=1);

namespace Logistics\Capacity\Domain;

use Logistics\Capacity\Domain\Event\DispatchSlotBlocked;
use Logistics\Capacity\Domain\Event\DispatchSlotUnblocked;
use Logistics\Capacity\Domain\Exception\CapacityException;
use Logistics\Capacity\Domain\ValueObject\DispatchBlackoutSlotId;
use Logistics\Capacity\Domain\ValueObject\SlotDate;
use Logistics\Capacity\Domain\ValueObject\TimeSlot;
use Logistics\Shared\Domain\Aggregate\AggregateRoot;

/**
 * Franja horaria bloqueada de despacho: una franja concreta (p. ej. "09:00-13:00") de un día en la que NO
 * se despacha, para toda la red (municipio null) o para un municipio puntual. A diferencia del día
 * bloqueado (DispatchBlackoutDay), acá el resto del día sigue disponible. Es la raíz de agregado.
 *
 * El motivo es texto libre obligatorio (para auditoría: "Mantenimiento de flota", "Corte de ruta", etc.).
 */
final class DispatchBlackoutSlot extends AggregateRoot
{
    private function __construct(
        private readonly DispatchBlackoutSlotId $id,
        private readonly SlotDate $date,
        private readonly TimeSlot $slot,
        private readonly ?string $municipalityId,
        private readonly string $reason,
        private readonly ?string $createdBy,
    ) {}

    /**
     * Bloquea una franja (nueva): graba el evento DispatchSlotBlocked.
     */
    public static function block(
        DispatchBlackoutSlotId $id,
        SlotDate $date,
        TimeSlot $slot,
        ?string $municipalityId,
        string $reason,
        ?string $createdBy = null,
    ): self {
        $municipalityId = self::normalizeMunicipality($municipalityId);
        $reason = trim($reason);
        if ($reason === '') {
            throw CapacityException::because('el motivo del bloqueo de franja no puede estar vacío');
        }

        $blackout = new self($id, $date, $slot, $municipalityId, $reason, $createdBy);
        $blackout->recordEvent(new DispatchSlotBlocked(
            $id->toString(),
            $date->toString(),
            $slot->toString(),
            $municipalityId,
            $reason,
        ));

        return $blackout;
    }

    /**
     * Rehidrata desde el estado persistido. No graba eventos.
     */
    public static function fromState(
        DispatchBlackoutSlotId $id,
        SlotDate $date,
        TimeSlot $slot,
        ?string $municipalityId,
        string $reason,
        ?string $createdBy,
    ): self {
        return new self($id, $date, $slot, self::normalizeMunicipality($municipalityId), $reason, $createdBy);
    }

    /**
     * Marca el desbloqueo: graba el evento DispatchSlotUnblocked. La baja de la fila la hace el
     * repositorio; el agregado solo registra el hecho para el outbox.
     */
    public function unblock(): void
    {
        $this->recordEvent(new DispatchSlotUnblocked(
            $this->id->toString(),
            $this->date->toString(),
            $this->slot->toString(),
            $this->municipalityId,
        ));
    }

    public function id(): DispatchBlackoutSlotId
    {
        return $this->id;
    }

    public function date(): SlotDate
    {
        return $this->date;
    }

    public function slot(): TimeSlot
    {
        return $this->slot;
    }

    public function municipalityId(): ?string
    {
        return $this->municipalityId;
    }

    public function isGlobal(): bool
    {
        return $this->municipalityId === null;
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function createdBy(): ?string
    {
        return $this->createdBy;
    }

    private static function normalizeMunicipality(?string $municipalityId): ?string
    {
        if ($municipalityId === null) {
            return null;
        }
        $municipalityId = trim($municipalityId);

        return $municipalityId === '' ? null : $municipalityId;
    }
}

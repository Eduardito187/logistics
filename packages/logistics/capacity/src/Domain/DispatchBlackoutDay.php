<?php

declare(strict_types=1);

namespace Logistics\Capacity\Domain;

use Logistics\Capacity\Domain\Event\DispatchDayBlocked;
use Logistics\Capacity\Domain\Event\DispatchDayUnblocked;
use Logistics\Capacity\Domain\Exception\CapacityException;
use Logistics\Capacity\Domain\ValueObject\DispatchBlackoutDayId;
use Logistics\Capacity\Domain\ValueObject\SlotDate;
use Logistics\Shared\Domain\Aggregate\AggregateRoot;

/**
 * Día bloqueado de despacho: un feriado u otro motivo por el que NO se despacha un día concreto, para
 * toda la red (municipio null) o para un municipio puntual. Es la raíz de agregado de los bloqueos.
 *
 * El motivo es texto libre obligatorio (para auditoría: "Feriado nacional", "Corte de ruta", etc.).
 */
final class DispatchBlackoutDay extends AggregateRoot
{
    private function __construct(
        private readonly DispatchBlackoutDayId $id,
        private readonly SlotDate $date,
        private readonly ?string $municipalityId,
        private readonly string $reason,
        private readonly ?string $createdBy,
    ) {}

    /**
     * Bloquea un día (nuevo): graba el evento DispatchDayBlocked.
     */
    public static function block(
        DispatchBlackoutDayId $id,
        SlotDate $date,
        ?string $municipalityId,
        string $reason,
        ?string $createdBy = null,
    ): self {
        $municipalityId = self::normalizeMunicipality($municipalityId);
        $reason = trim($reason);
        if ($reason === '') {
            throw CapacityException::because('el motivo del bloqueo no puede estar vacío');
        }

        $day = new self($id, $date, $municipalityId, $reason, $createdBy);
        $day->recordEvent(new DispatchDayBlocked($id->toString(), $date->toString(), $municipalityId, $reason));

        return $day;
    }

    /**
     * Rehidrata desde el estado persistido. No graba eventos.
     */
    public static function fromState(
        DispatchBlackoutDayId $id,
        SlotDate $date,
        ?string $municipalityId,
        string $reason,
        ?string $createdBy,
    ): self {
        return new self($id, $date, self::normalizeMunicipality($municipalityId), $reason, $createdBy);
    }

    /**
     * Marca el desbloqueo: graba el evento DispatchDayUnblocked. La baja de la fila la hace el
     * repositorio; el agregado solo registra el hecho para el outbox.
     */
    public function unblock(): void
    {
        $this->recordEvent(new DispatchDayUnblocked($this->id->toString(), $this->date->toString(), $this->municipalityId));
    }

    public function id(): DispatchBlackoutDayId
    {
        return $this->id;
    }

    public function date(): SlotDate
    {
        return $this->date;
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

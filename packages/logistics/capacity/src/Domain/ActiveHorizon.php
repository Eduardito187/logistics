<?php

declare(strict_types=1);

namespace Logistics\Capacity\Domain;

use Logistics\Capacity\Domain\Event\CapacityWindowActivated;
use Logistics\Capacity\Domain\ValueObject\ActiveHorizonId;
use Logistics\Capacity\Domain\ValueObject\SlotDate;
use Logistics\Shared\Domain\Aggregate\AggregateRoot;

/**
 * Horizonte activo de cupos de un alcance: hasta qué fecha hay cupos activados ("cupos activos hasta
 * <fecha>") para un municipio (o global, con municipio null). Es la raíz de agregado que registra el
 * avance del horizonte y lo que consulta el enforcement del consumo.
 *
 * El horizonte solo avanza: extendTo nunca lo retrocede (reactivar un rango que termina antes no acorta
 * lo ya ofrecido). Cada avance graba CapacityWindowActivated.
 */
final class ActiveHorizon extends AggregateRoot
{
    private function __construct(
        private readonly ActiveHorizonId $id,
        private readonly ?string $municipalityId,
        private SlotDate $activeUntil,
    ) {}

    /**
     * Inicia el horizonte de un alcance hasta una fecha: graba CapacityWindowActivated.
     */
    public static function start(ActiveHorizonId $id, ?string $municipalityId, SlotDate $activeUntil): self
    {
        $municipalityId = self::normalizeMunicipality($municipalityId);
        $horizon = new self($id, $municipalityId, $activeUntil);
        $horizon->recordEvent(new CapacityWindowActivated($id->toString(), $municipalityId, $activeUntil->toString()));

        return $horizon;
    }

    /**
     * Rehidrata desde el estado persistido. No graba eventos.
     */
    public static function fromState(ActiveHorizonId $id, ?string $municipalityId, SlotDate $activeUntil): self
    {
        return new self($id, self::normalizeMunicipality($municipalityId), $activeUntil);
    }

    /**
     * Mueve el horizonte hacia adelante hasta la fecha dada si es posterior a la actual. Si no avanza,
     * devuelve false y no graba evento (idempotente ante reactivaciones de rangos ya cubiertos).
     */
    public function extendTo(SlotDate $until): bool
    {
        if (!$until->isAfter($this->activeUntil)) {
            return false;
        }

        $this->activeUntil = $until;
        $this->recordEvent(new CapacityWindowActivated($this->id->toString(), $this->municipalityId, $until->toString()));

        return true;
    }

    /**
     * ¿La fecha cae dentro del horizonte activo (es anterior o igual a active_until)?
     */
    public function covers(SlotDate $date): bool
    {
        return $date->isOnOrBefore($this->activeUntil);
    }

    public function id(): ActiveHorizonId
    {
        return $this->id;
    }

    public function municipalityId(): ?string
    {
        return $this->municipalityId;
    }

    public function activeUntil(): SlotDate
    {
        return $this->activeUntil;
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

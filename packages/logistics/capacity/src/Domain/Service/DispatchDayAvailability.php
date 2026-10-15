<?php

declare(strict_types=1);

namespace Logistics\Capacity\Domain\Service;

use Logistics\Capacity\Domain\Contract\DispatchBlackoutRepository;
use Logistics\Capacity\Domain\ValueObject\SlotDate;

/**
 * Responde si un (municipio, fecha) está disponible para despacho o bloqueado. Es el servicio de
 * dominio que consulta ConsumeCapacity (enforcement), el generador de activación por rango (para saltar
 * los días bloqueados) y la oferta de slots. Centraliza la regla "un bloqueo global tapa a todos los
 * municipios".
 *
 * Es PHP puro salvo por depender del puerto del repositorio (una interfaz del dominio): no toca
 * framework ni persistencia concreta.
 */
final readonly class DispatchDayAvailability
{
    public function __construct(private DispatchBlackoutRepository $blackouts) {}

    /**
     * ¿Está bloqueada la fecha para el municipio? Un bloqueo específico del municipio O un bloqueo
     * global (municipio null) la marcan como bloqueada.
     */
    public function isBlocked(SlotDate $date, ?string $municipalityId): bool
    {
        return $this->blackouts->isBlocked($date, $municipalityId);
    }

    /**
     * ¿Se puede despachar ese día? Negación de isBlocked, para leerse como regla positiva en quien
     * planifica u ofrece slots.
     */
    public function isAvailable(SlotDate $date, ?string $municipalityId): bool
    {
        return !$this->isBlocked($date, $municipalityId);
    }
}

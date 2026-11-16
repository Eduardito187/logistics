<?php

declare(strict_types=1);

namespace Logistics\Capacity\Domain\Contract;

use Logistics\Capacity\Domain\DispatchBlackoutSlot;
use Logistics\Capacity\Domain\ValueObject\DispatchBlackoutSlotId;
use Logistics\Capacity\Domain\ValueObject\SlotDate;
use Logistics\Capacity\Domain\ValueObject\TimeSlot;

/**
 * Puerto de persistencia de las franjas horarias bloqueadas de despacho. El dominio lo define; la
 * infraestructura (Eloquent) lo implementa.
 */
interface DispatchBlackoutSlotRepository
{
    public function nextIdentity(): DispatchBlackoutSlotId;

    public function save(DispatchBlackoutSlot $slot): void;

    public function remove(DispatchBlackoutSlot $slot): void;

    /**
     * El bloqueo EXACTO de una fecha, franja y municipio (o el global, con municipio null). Devuelve null
     * si no existe. Es la clave natural del bloqueo de franja.
     */
    public function findByKey(SlotDate $date, TimeSlot $slot, ?string $municipalityId): ?DispatchBlackoutSlot;

    /**
     * ¿Está bloqueada esa franja para el municipio dado? Considera tanto el bloqueo específico del
     * municipio como el bloqueo GLOBAL de la franja (municipio null): si cualquiera existe, está bloqueada.
     */
    public function isBlocked(SlotDate $date, TimeSlot $slot, ?string $municipalityId): bool;

    /**
     * Franjas bloqueadas de una fecha que afectan al municipio dado (las suyas y las globales). Con
     * municipio null, solo las globales. Ordenadas por franja de forma estable. Alimenta el calendario de
     * franjas del día (GET /day).
     *
     * @return list<DispatchBlackoutSlot>
     */
    public function listForDate(SlotDate $date, ?string $municipalityId): array;
}

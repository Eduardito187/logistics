<?php

declare(strict_types=1);

namespace Logistics\Capacity\Domain\Contract;

use Logistics\Capacity\Domain\DispatchBlackoutDay;
use Logistics\Capacity\Domain\ValueObject\DispatchBlackoutDayId;
use Logistics\Capacity\Domain\ValueObject\SlotDate;

/**
 * Puerto de persistencia de los días bloqueados de despacho. El dominio lo define; la infraestructura
 * (Eloquent) lo implementa.
 */
interface DispatchBlackoutRepository
{
    public function nextIdentity(): DispatchBlackoutDayId;

    public function save(DispatchBlackoutDay $day): void;

    public function remove(DispatchBlackoutDay $day): void;

    /**
     * El bloqueo EXACTO de una fecha y municipio (o el global, con municipio null). Devuelve null si no
     * existe. Es la clave natural del bloqueo.
     */
    public function findByDate(SlotDate $date, ?string $municipalityId): ?DispatchBlackoutDay;

    /**
     * ¿Está bloqueada la fecha para el municipio dado? Considera tanto el bloqueo específico del
     * municipio como el bloqueo GLOBAL (municipio null): si cualquiera existe, el día está bloqueado.
     */
    public function isBlocked(SlotDate $date, ?string $municipalityId): bool;

    /**
     * Días bloqueados dentro de un rango (ambos extremos incluidos), opcionalmente acotado a un
     * municipio. Cuando se pasa un municipio, incluye también los bloqueos globales que lo afectan.
     * Ordenados por fecha ascendente de forma estable.
     *
     * @return list<DispatchBlackoutDay>
     */
    public function listInRange(SlotDate $from, SlotDate $to, ?string $municipalityId = null): array;
}

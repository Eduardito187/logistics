<?php

declare(strict_types=1);

namespace Logistics\Notifications\Application\Contract;

use Logistics\Notifications\Domain\ValueObject\CascadePolicy;
use Logistics\Notifications\Domain\ValueObject\MessageType;

/**
 * Puerto (propiedad de Notificaciones) hacia las reglas de cascada guardadas como configuración
 * versionada en el contexto Configuración (clave `notifications.cascade_rules`).
 *
 * Es la frontera entre contextos: el motor de cascada LEE sus overrides desde acá (no desde código ni
 * desde una tabla propia), y el panel los ESCRIBE acá, generando una versión nueva con historial. La
 * implementación concreta (Infrastructure) traduce a/desde el contexto Configuración por los buses CQRS.
 */
interface CascadeRuleSettings
{
    /**
     * Overrides vigentes por tipo de mensaje (solo los personalizados; los demás usan el default).
     *
     * @return array<string, CascadePolicy> keyed por el valor del enum MessageType
     */
    public function configured(): array;

    /**
     * Override vigente de un tipo, o null si no hay (el llamador cae al default del ADR 006).
     */
    public function policyFor(MessageType $messageType): ?CascadePolicy;

    /**
     * Publica (upsert) el override de un tipo: crea una versión nueva de la clave, sin pisar el historial.
     */
    public function save(MessageType $messageType, CascadePolicy $policy, ?string $createdBy = null): void;

    /**
     * Quita el override de un tipo (vuelve al default): también crea una versión nueva.
     */
    public function remove(MessageType $messageType, ?string $createdBy = null): void;

    /**
     * Historial de versiones de la clave de cascada, de la más nueva a la más vieja, en forma primitiva.
     *
     * @return list<array{version: int, effective_from: string, created_at: string, created_by: string|null, rule_count: int}>
     */
    public function history(): array;
}

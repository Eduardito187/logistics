<?php

declare(strict_types=1);

namespace Logistics\Notifications\Domain\Contract;

use Logistics\Notifications\Domain\ValueObject\CascadePolicy;
use Logistics\Notifications\Domain\ValueObject\MessageType;

/**
 * Puerta de las reglas de cascada configurables por tipo de mensaje (P1-NOT-01). El dominio la define;
 * la implementación (tabla propia editable desde el panel) vive en Infrastructure.
 *
 * Es la fuente de verdad de la política por tipo cuando el operador la personaliza; si no hay regla
 * definida para un tipo, la capa de aplicación cae a la cascada por defecto del ADR 006.
 */
interface MessageTypeRuleRepository
{
    /**
     * Política configurada para un tipo de mensaje, o null si no hay una (usar el default del ADR).
     */
    public function policyFor(MessageType $messageType): ?CascadePolicy;

    /**
     * Guarda (upsert) la política de un tipo de mensaje.
     */
    public function save(MessageType $messageType, CascadePolicy $policy): void;

    /**
     * Quita la política configurada de un tipo (si existe). No falla si no había ninguna.
     */
    public function remove(MessageType $messageType): void;

    /**
     * Todas las políticas configuradas, keyed por MessageType->value.
     *
     * @return array<string, CascadePolicy>
     */
    public function all(): array;
}

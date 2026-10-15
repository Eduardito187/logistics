<?php

declare(strict_types=1);

namespace Logistics\Access\Application\Command\RecordAuditEntry;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de dejar constancia en la bitácora de una acción sensible del módulo de acceso.
 *
 * El autor (actorId/actorLabel) y la IP los resuelve la capa de presentación a partir de la sesión del
 * admin; el contexto son primitivos serializables a JSON (p. ej. roles agregados/quitados).
 */
final readonly class RecordAuditEntry implements Command
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public string $action,
        public string $subjectType,
        public ?string $subjectId = null,
        public ?string $actorId = null,
        public string $actorLabel = 'System',
        public array $context = [],
        public ?string $ipAddress = null,
    ) {}
}

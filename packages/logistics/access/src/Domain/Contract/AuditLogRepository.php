<?php

declare(strict_types=1);

namespace Logistics\Access\Domain\Contract;

use Logistics\Access\Domain\Model\AuditEntry;
use Logistics\Access\Domain\ValueObject\AuditEntryId;

/**
 * Puerto de persistencia de la bitácora de auditoría. Es append-only: solo se agrega y se lee, nunca se
 * actualiza ni se borra una entrada ya grabada.
 */
interface AuditLogRepository
{
    public function nextIdentity(): AuditEntryId;

    /**
     * Agrega una entrada nueva a la bitácora.
     */
    public function append(AuditEntry $entry): void;

    /**
     * Devuelve las entradas más recientes, opcionalmente filtradas por acción y/o tipo de sujeto.
     *
     * @return list<AuditEntry>
     */
    public function recent(int $limit, ?string $action = null, ?string $subjectType = null): array;
}

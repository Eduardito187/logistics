<?php

declare(strict_types=1);

namespace Logistics\Execution\Domain\Contract;

use Logistics\Execution\Domain\Model\DeliveryIssue;
use Logistics\Execution\Domain\ValueObject\DeliveryIssueId;

/**
 * Repositorio de inconvenientes de entrega (hechos append-only del chofer en ruta).
 *
 * El dominio solo conoce este contrato; la implementación vive en Infrastructure. La idempotencia por
 * clave de la app offline la verifica el handler con existsByIdempotencyKey antes de registrar el hecho.
 */
interface DeliveryIssueRepository
{
    public function nextIdentity(): DeliveryIssueId;

    public function save(DeliveryIssue $issue): void;

    public function existsByIdempotencyKey(?string $idempotencyKey): bool;
}

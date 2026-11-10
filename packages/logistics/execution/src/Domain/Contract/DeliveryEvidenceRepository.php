<?php

declare(strict_types=1);

namespace Logistics\Execution\Domain\Contract;

use Logistics\Execution\Domain\Model\DeliveryEvidence;
use Logistics\Execution\Domain\ValueObject\DeliveryEvidenceId;

/**
 * Repositorio de evidencias de entrega ligadas al cliente (anti-fraude).
 *
 * El dominio solo conoce este contrato; la implementación vive en Infrastructure. La idempotencia de la
 * captura (app offline) la verifica el handler con existsByIdempotencyKey. El validador asíncrono carga
 * la evidencia por id, aplica el resultado y la vuelve a guardar.
 */
interface DeliveryEvidenceRepository
{
    public function nextIdentity(): DeliveryEvidenceId;

    public function save(DeliveryEvidence $evidence): void;

    public function findById(DeliveryEvidenceId $id): ?DeliveryEvidence;

    public function existsByIdempotencyKey(?string $idempotencyKey): bool;
}

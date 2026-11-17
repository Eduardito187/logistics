<?php

declare(strict_types=1);

namespace Logistics\Execution\Domain\Contract;

use Logistics\Execution\Domain\Model\FailureReason;
use Logistics\Execution\Domain\ValueObject\FailureReasonCode;
use Logistics\Execution\Domain\ValueObject\FailureReasonId;

/**
 * Repositorio del catálogo de motivos de entrega fallida. El dominio solo conoce este contrato; la
 * implementación Eloquent vive en Infrastructure.
 */
interface FailureReasonRepository
{
    public function nextIdentity(): FailureReasonId;

    public function save(FailureReason $reason): void;

    public function findByCode(FailureReasonCode $code): ?FailureReason;

    /**
     * Todos los motivos (activos e inactivos), ordenados por sort_order y luego code. Para el admin.
     *
     * @return list<FailureReason>
     */
    public function all(): array;

    /**
     * Solo los motivos activos, ordenados por sort_order y luego code. Para el chofer.
     *
     * @return list<FailureReason>
     */
    public function allActiveOrdered(): array;

    public function existsByCode(FailureReasonCode $code): bool;
}

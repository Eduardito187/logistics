<?php

declare(strict_types=1);

namespace Logistics\Compensation\Domain\Contract;

use Logistics\Compensation\Domain\Model\CompensationCase;
use Logistics\Compensation\Domain\ValueObject\CompensationCaseId;
use Logistics\Compensation\Domain\ValueObject\Money;

/**
 * Repositorio del agregado CompensationCase. Contrato de dominio; la implementación vive en Infrastructure.
 */
interface CompensationCaseRepository
{
    public function nextIdentity(): CompensationCaseId;

    public function save(CompensationCase $case): void;

    public function findById(CompensationCaseId $id): ?CompensationCase;

    /**
     * Busca un caso por su clave de idempotencia (trigger:shipment o el event_id de origen). Devuelve null
     * si no existe. Permite que un reactor reentregado por el outbox no abra el caso dos veces.
     */
    public function findByIdempotencyKey(string $idempotencyKey): ?CompensationCase;

    /**
     * Suma del monto efectivo ya comprometido para una orden (casos propuestos/aprobados/emitidos; NO los
     * rechazados ni los no compensables). Base del tope acumulado por orden (§2.1.2, regla 3).
     */
    public function sumCommittedForOrder(string $orderReference, string $currency): Money;
}

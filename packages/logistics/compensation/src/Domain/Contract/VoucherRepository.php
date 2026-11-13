<?php

declare(strict_types=1);

namespace Logistics\Compensation\Domain\Contract;

use DateTimeImmutable;
use Logistics\Compensation\Domain\Model\Voucher;
use Logistics\Compensation\Domain\ValueObject\CompensationCaseId;
use Logistics\Compensation\Domain\ValueObject\VoucherCode;
use Logistics\Compensation\Domain\ValueObject\VoucherId;

/**
 * Repositorio del agregado Voucher. Contrato de dominio; la implementación vive en Infrastructure.
 */
interface VoucherRepository
{
    public function nextIdentity(): VoucherId;

    public function save(Voucher $voucher): void;

    public function findById(VoucherId $id): ?Voucher;

    /**
     * Busca por código. El canje usa un bloqueo pesimista (SELECT … FOR UPDATE) para que dos canjes
     * concurrentes del mismo código no ganen los dos; eso lo decide la implementación con
     * findByCodeForUpdate().
     */
    public function findByCode(VoucherCode $code): ?Voucher;

    /**
     * Igual que findByCode pero tomando el candado de fila (para el canje transaccional del POS).
     */
    public function findByCodeForUpdate(VoucherCode $code): ?Voucher;

    public function findByCaseId(CompensationCaseId $caseId): ?Voucher;

    /**
     * Ids de los vales emitidos cuyo vencimiento ya pasó (candidatos a vencer). Usa el índice parcial.
     *
     * @return list<VoucherId>
     */
    public function idsExpiredAsOf(DateTimeImmutable $now, int $limit): array;
}

<?php

declare(strict_types=1);

namespace Logistics\Compensation\Infrastructure\Persistence\Eloquent\Repository;

use DateTimeImmutable;
use Logistics\Compensation\Domain\Contract\VoucherRepository;
use Logistics\Compensation\Domain\Model\Voucher;
use Logistics\Compensation\Domain\ValueObject\CompensationCaseId;
use Logistics\Compensation\Domain\ValueObject\VoucherCode;
use Logistics\Compensation\Domain\ValueObject\VoucherId;
use Logistics\Compensation\Domain\ValueObject\VoucherStatus;
use Logistics\Compensation\Infrastructure\Persistence\Eloquent\Mapper\VoucherMapper;
use Logistics\Compensation\Infrastructure\Persistence\Eloquent\Model\VoucherRecord;

/**
 * Implementación Eloquent del repositorio de vales. El canje usa lockForUpdate (SELECT … FOR UPDATE) para
 * serializar dos canjes concurrentes del mismo vale.
 */
final readonly class EloquentVoucherRepository implements VoucherRepository
{
    public function __construct(
        private VoucherMapper $mapper = new VoucherMapper,
    ) {}

    public function nextIdentity(): VoucherId
    {
        return VoucherId::generate();
    }

    public function save(Voucher $voucher): void
    {
        $row = $this->mapper->toRow($voucher);
        VoucherRecord::query()->updateOrCreate(['id' => $row['id']], $row);
    }

    public function findById(VoucherId $id): ?Voucher
    {
        $record = VoucherRecord::query()->find($id->toString());

        return $record instanceof VoucherRecord ? $this->mapper->toDomain($record) : null;
    }

    public function findByCode(VoucherCode $code): ?Voucher
    {
        $record = VoucherRecord::query()->where('code', $code->value)->first();

        return $record instanceof VoucherRecord ? $this->mapper->toDomain($record) : null;
    }

    public function findByCodeForUpdate(VoucherCode $code): ?Voucher
    {
        $record = VoucherRecord::query()->where('code', $code->value)->lockForUpdate()->first();

        return $record instanceof VoucherRecord ? $this->mapper->toDomain($record) : null;
    }

    public function findByCaseId(CompensationCaseId $caseId): ?Voucher
    {
        $record = VoucherRecord::query()->where('case_id', $caseId->toString())->first();

        return $record instanceof VoucherRecord ? $this->mapper->toDomain($record) : null;
    }

    public function idsExpiredAsOf(DateTimeImmutable $now, int $limit): array
    {
        $ids = VoucherRecord::query()
            ->where('status', VoucherStatus::Issued->value)
            ->where('expires_at', '<=', $now)
            ->orderBy('expires_at')
            ->limit($limit)
            ->pluck('id')
            ->all();

        return array_values(array_map(
            static fn (string $id): VoucherId => VoucherId::fromString($id),
            $ids,
        ));
    }
}

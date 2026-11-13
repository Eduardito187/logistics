<?php

declare(strict_types=1);

namespace Logistics\Compensation\Infrastructure\Persistence\Eloquent\Mapper;

use Logistics\Compensation\Domain\Model\Voucher;
use Logistics\Compensation\Domain\ValueObject\CompensationCaseId;
use Logistics\Compensation\Domain\ValueObject\Money;
use Logistics\Compensation\Domain\ValueObject\VoucherCode;
use Logistics\Compensation\Domain\ValueObject\VoucherId;
use Logistics\Compensation\Domain\ValueObject\VoucherStatus;
use Logistics\Compensation\Infrastructure\Persistence\Eloquent\Model\VoucherRecord;

/**
 * Traduce entre el agregado Voucher y su fila Eloquent.
 */
final readonly class VoucherMapper
{
    /**
     * @return array<string, mixed>
     */
    public function toRow(Voucher $voucher): array
    {
        return [
            'id' => $voucher->id()->toString(),
            'case_id' => $voucher->caseId()->toString(),
            'code' => $voucher->code()->value,
            'amount_cents' => $voucher->amount()->cents,
            'currency' => $voucher->amount()->currency,
            'holder_email_hash' => $voucher->holderEmailHash(),
            'holder_email' => $voucher->holderEmail(),
            'expires_at' => $voucher->expiresAt(),
            'status' => $voucher->status()->value,
            'magento_rule_id' => $voucher->magentoRuleId(),
            'magento_sync_status' => $voucher->magentoSyncStatus(),
            'held_until' => $voucher->heldUntil(),
            'held_by_channel' => $voucher->heldByChannel(),
            'redeemed_at' => $voucher->redeemedAt(),
            'redeemed_channel' => $voucher->redeemedChannel(),
            'redeemed_sale_ref' => $voucher->redeemedSaleRef(),
        ];
    }

    public function toDomain(VoucherRecord $record): Voucher
    {
        return Voucher::reconstitute(
            id: VoucherId::fromString($record->id),
            caseId: CompensationCaseId::fromString($record->case_id),
            code: VoucherCode::fromString($record->code),
            amount: Money::of($record->amount_cents, $record->currency),
            holderEmail: $record->holder_email,
            holderEmailHash: $record->holder_email_hash,
            expiresAt: $record->expires_at->toDateTimeImmutable(),
            status: VoucherStatus::from($record->status),
            magentoRuleId: $record->magento_rule_id,
            magentoSyncStatus: $record->magento_sync_status,
            heldUntil: $record->held_until?->toDateTimeImmutable(),
            heldByChannel: $record->held_by_channel,
            redeemedAt: $record->redeemed_at?->toDateTimeImmutable(),
            redeemedChannel: $record->redeemed_channel,
            redeemedSaleRef: $record->redeemed_sale_ref,
        );
    }
}

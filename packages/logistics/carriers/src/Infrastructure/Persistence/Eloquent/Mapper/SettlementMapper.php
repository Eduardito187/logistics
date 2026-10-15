<?php

declare(strict_types=1);

namespace Logistics\Carriers\Infrastructure\Persistence\Eloquent\Mapper;

use Logistics\Carriers\Domain\Model\Settlement;
use Logistics\Carriers\Domain\ValueObject\BillingPeriod;
use Logistics\Carriers\Domain\ValueObject\CorridorRef;
use Logistics\Carriers\Domain\ValueObject\Money;
use Logistics\Carriers\Domain\ValueObject\SettlementId;
use Logistics\Carriers\Domain\ValueObject\SettlementStatus;
use Logistics\Carriers\Infrastructure\Persistence\Eloquent\Model\SettlementRecord;

/**
 * Traduce entre el agregado Settlement (dominio) y su fila Eloquent. Único punto que conoce ambos.
 */
final class SettlementMapper
{
    public function toDomain(SettlementRecord $record): Settlement
    {
        return Settlement::reconstitute(
            SettlementId::fromString($record->id),
            $record->carrier_id,
            CorridorRef::of($record->corridor_from, $record->corridor_to, $record->corridor_type),
            BillingPeriod::of($record->period),
            Money::of($record->amount_cents, $record->currency),
            $record->reference,
            SettlementStatus::from($record->status),
            $record->dispute_reason,
            $record->payment_reference,
            array_values($record->documents),
        );
    }

    /**
     * @return array{id: string, carrier_id: string, corridor_from: string, corridor_to: string, corridor_type: string, period: string, amount_cents: int, currency: string, reference: string, status: string, dispute_reason: string|null, payment_reference: string|null, documents: list<array{type: string, reference: string}>}
     */
    public function toRow(Settlement $settlement): array
    {
        return [
            'id' => $settlement->id()->toString(),
            'carrier_id' => $settlement->carrierId(),
            'corridor_from' => $settlement->corridor()->from,
            'corridor_to' => $settlement->corridor()->to,
            'corridor_type' => $settlement->corridor()->type,
            'period' => $settlement->period()->toString(),
            'amount_cents' => $settlement->amount()->cents,
            'currency' => $settlement->amount()->currency,
            'reference' => $settlement->reference(),
            'status' => $settlement->status()->value,
            'dispute_reason' => $settlement->disputeReason(),
            'payment_reference' => $settlement->paymentReference(),
            'documents' => array_values($settlement->documents()),
        ];
    }
}

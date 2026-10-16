<?php

declare(strict_types=1);

namespace Logistics\Settlement\Infrastructure\Persistence\Eloquent\Mapper;

use Logistics\Settlement\Domain\Model\SellerSettlement;
use Logistics\Settlement\Domain\ValueObject\AppliedFees;
use Logistics\Settlement\Domain\ValueObject\Money;
use Logistics\Settlement\Domain\ValueObject\SettlementId;
use Logistics\Settlement\Domain\ValueObject\SettlementLine;
use Logistics\Settlement\Domain\ValueObject\SettlementPeriod;
use Logistics\Settlement\Domain\ValueObject\SettlementStatus;
use Logistics\Settlement\Infrastructure\Persistence\Eloquent\Model\SellerSettlementRecord;

/**
 * Traduce entre el agregado SellerSettlement (dominio) y sus filas Eloquent. Único punto que conoce ambos.
 */
final class SellerSettlementMapper
{
    public function toDomain(SellerSettlementRecord $record): SellerSettlement
    {
        $currency = $record->currency;

        $lines = [];
        foreach ($record->lines as $lineRecord) {
            $lines[] = SettlementLine::of(
                $lineRecord->shipment_id,
                $lineRecord->dispatch_reference,
                Money::of($lineRecord->base_amount_cents, $lineRecord->currency),
            );
        }

        $fees = AppliedFees::of(
            $record->commission_basis_points,
            Money::of($record->commission_cents, $currency),
            Money::of($record->fixed_fee_cents, $currency),
            Money::of($record->storage_fee_cents, $currency),
            Money::of($record->fulfillment_fee_cents, $currency),
            Money::of($record->last_mile_fee_cents, $currency),
        );

        return SellerSettlement::reconstitute(
            SettlementId::fromString($record->id),
            $record->seller_id,
            SettlementPeriod::of($record->period_from, $record->period_to),
            $lines,
            Money::of($record->gross_cents, $currency),
            $fees,
            Money::of($record->net_cents, $currency),
            SettlementStatus::from($record->status),
            $record->payment_reference,
        );
    }

    /**
     * Fila de cabecera de la liquidación (las líneas se persisten aparte, ver el repositorio).
     *
     * @return array{id: string, seller_id: string, period_from: string, period_to: string, gross_cents: int, currency: string, commission_basis_points: int, commission_cents: int, fixed_fee_cents: int, storage_fee_cents: int, fulfillment_fee_cents: int, last_mile_fee_cents: int, net_cents: int, status: string, payment_reference: string|null}
     */
    public function toRow(SellerSettlement $settlement): array
    {
        $fees = $settlement->fees();

        return [
            'id' => $settlement->id()->toString(),
            'seller_id' => $settlement->sellerId(),
            'period_from' => $settlement->period()->fromString(),
            'period_to' => $settlement->period()->toStringEnd(),
            'gross_cents' => $settlement->gross()->cents,
            'currency' => $settlement->net()->currency,
            'commission_basis_points' => $fees->commissionBasisPoints,
            'commission_cents' => $fees->commission->cents,
            'fixed_fee_cents' => $fees->fixedFee->cents,
            'storage_fee_cents' => $fees->storageFee->cents,
            'fulfillment_fee_cents' => $fees->fulfillmentFee->cents,
            'last_mile_fee_cents' => $fees->lastMileFee->cents,
            'net_cents' => $settlement->net()->cents,
            'status' => $settlement->status()->value,
            'payment_reference' => $settlement->paymentReference(),
        ];
    }

    /**
     * Filas de líneas de la liquidación, listas para insertar en `seller_settlement_lines`.
     *
     * @return list<array{settlement_id: string, shipment_id: string, dispatch_reference: string, base_amount_cents: int, currency: string}>
     */
    public function lineRows(SellerSettlement $settlement): array
    {
        return array_map(
            static fn (SettlementLine $line): array => [
                'settlement_id' => $settlement->id()->toString(),
                'shipment_id' => $line->shipmentId,
                'dispatch_reference' => $line->dispatchReference,
                'base_amount_cents' => $line->baseAmount->cents,
                'currency' => $line->baseAmount->currency,
            ],
            $settlement->lines(),
        );
    }
}

<?php

declare(strict_types=1);

namespace Logistics\Sellers\Infrastructure\Persistence\Eloquent\Mapper;

use Logistics\Sellers\Domain\Model\Seller;
use Logistics\Sellers\Domain\ValueObject\ContactInfo;
use Logistics\Sellers\Domain\ValueObject\SellerId;
use Logistics\Sellers\Domain\ValueObject\SellerStatus;
use Logistics\Sellers\Domain\ValueObject\SettlementTerms;
use Logistics\Sellers\Infrastructure\Persistence\Eloquent\Model\SellerRecord;

/**
 * Traduce entre el agregado Seller (dominio) y su fila Eloquent + la fila de términos de liquidación.
 * Único punto que conoce ambos lados.
 */
final class SellerMapper
{
    public function toDomain(SellerRecord $record): Seller
    {
        $terms = $record->settlementTerms;

        return Seller::reconstitute(
            SellerId::fromString($record->id),
            $record->legal_name,
            $record->tax_id,
            SellerStatus::from($record->status),
            ContactInfo::of($record->contact_name, $record->contact_email, $record->contact_phone),
            $terms !== null
                ? SettlementTerms::fromPrimitives(
                    $terms->commission_basis_points,
                    $terms->fixed_fee_cents,
                    $terms->currency,
                    $terms->payment_cycle,
                )
                : null,
        );
    }

    /**
     * Fila de la tabla `sellers`.
     *
     * @return array{id: string, legal_name: string, tax_id: string, status: string, contact_name: string, contact_email: string, contact_phone: ?string}
     */
    public function toRow(Seller $seller): array
    {
        $contact = $seller->contact();

        return [
            'id' => $seller->id()->toString(),
            'legal_name' => $seller->legalName(),
            'tax_id' => $seller->taxId(),
            'status' => $seller->status()->value,
            'contact_name' => $contact->contactName,
            'contact_email' => $contact->email,
            'contact_phone' => $contact->phone,
        ];
    }

    /**
     * Fila de la tabla `seller_settlement_terms`, o null si el seller aún no tiene términos pactados.
     *
     * @return array{commission_basis_points: int, fixed_fee_cents: int, currency: string, payment_cycle: string}|null
     */
    public function toTermsRow(Seller $seller): ?array
    {
        $terms = $seller->settlementTerms();
        if ($terms === null) {
            return null;
        }

        return [
            'commission_basis_points' => $terms->commissionBasisPoints,
            'fixed_fee_cents' => $terms->fixedFee->cents,
            'currency' => $terms->fixedFee->currency,
            'payment_cycle' => $terms->paymentCycle->value,
        ];
    }
}

<?php

declare(strict_types=1);

namespace Logistics\Sellers\Application\ReadModel;

use Logistics\Sellers\Domain\Model\Seller;

/**
 * Proyección de lectura de un seller para listados y API. Primitivos: no expone objetos de dominio.
 */
final readonly class SellerView
{
    /**
     * @param array{contact_name: string, email: string, phone: ?string}                                                      $contact
     * @param array{commission_basis_points: int, fixed_fee: array{cents: int, currency: string}, payment_cycle: string}|null $settlementTerms
     */
    private function __construct(
        public string $id,
        public string $legalName,
        public string $taxId,
        public string $status,
        public array $contact,
        public ?array $settlementTerms,
    ) {}

    public static function fromDomain(Seller $seller): self
    {
        return new self(
            $seller->id()->toString(),
            $seller->legalName(),
            $seller->taxId(),
            $seller->status()->value,
            $seller->contact()->toArray(),
            $seller->settlementTerms()?->toArray(),
        );
    }

    /**
     * @return array{id: string, legal_name: string, tax_id: string, status: string, contact: array{contact_name: string, email: string, phone: ?string}, settlement_terms: array{commission_basis_points: int, fixed_fee: array{cents: int, currency: string}, payment_cycle: string}|null}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'legal_name' => $this->legalName,
            'tax_id' => $this->taxId,
            'status' => $this->status,
            'contact' => $this->contact,
            'settlement_terms' => $this->settlementTerms,
        ];
    }
}

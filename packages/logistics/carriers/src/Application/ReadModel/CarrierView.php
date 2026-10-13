<?php

declare(strict_types=1);

namespace Logistics\Carriers\Application\ReadModel;

use Logistics\Carriers\Domain\Model\Carrier;

/**
 * Proyección de lectura de un transportista para listados y API. Primitivos: no expone objetos de dominio.
 */
final readonly class CarrierView
{
    /**
     * @param list<array{type: string, reference: string}> $documents
     */
    private function __construct(
        public string $id,
        public string $name,
        public string $taxId,
        public string $status,
        public ?float $averageRating,
        public int $ratingCount,
        public array $documents,
    ) {}

    public static function fromDomain(Carrier $carrier): self
    {
        return new self(
            $carrier->id()->toString(),
            $carrier->name(),
            $carrier->taxId(),
            $carrier->status()->value,
            $carrier->averageRating(),
            $carrier->ratingCount(),
            $carrier->documents(),
        );
    }

    /**
     * @return array{id: string, name: string, tax_id: string, status: string, average_rating: float|null, rating_count: int, documents: list<array{type: string, reference: string}>}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'tax_id' => $this->taxId,
            'status' => $this->status,
            'average_rating' => $this->averageRating,
            'rating_count' => $this->ratingCount,
            'documents' => $this->documents,
        ];
    }
}

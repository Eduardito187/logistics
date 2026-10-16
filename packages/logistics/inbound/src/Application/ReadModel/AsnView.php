<?php

declare(strict_types=1);

namespace Logistics\Inbound\Application\ReadModel;

use Logistics\Inbound\Domain\Model\Asn;
use Logistics\Inbound\Domain\ValueObject\AsnLine;

/**
 * Proyección de lectura de un ASN para listados y API. Primitivos: no expone objetos de dominio.
 */
final readonly class AsnView
{
    /**
     * @param list<array{sku: string, expected_quantity: int}>                                  $lines
     * @param array{date: string, start_time: string, end_time: string, time_slot: string}|null $appointment
     */
    private function __construct(
        public string $id,
        public string $sellerId,
        public string $warehouseId,
        public string $status,
        public array $lines,
        public ?array $appointment,
    ) {}

    public static function fromDomain(Asn $asn): self
    {
        $window = $asn->appointmentWindow();

        return new self(
            $asn->id()->toString(),
            $asn->sellerId(),
            $asn->warehouseId(),
            $asn->status()->value,
            array_map(static fn (AsnLine $line): array => $line->toArray(), $asn->lines()),
            $window?->toArray(),
        );
    }

    /**
     * @return array{id: string, seller_id: string, warehouse_id: string, status: string, lines: list<array{sku: string, expected_quantity: int}>, appointment: array{date: string, start_time: string, end_time: string, time_slot: string}|null}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'seller_id' => $this->sellerId,
            'warehouse_id' => $this->warehouseId,
            'status' => $this->status,
            'lines' => $this->lines,
            'appointment' => $this->appointment,
        ];
    }
}

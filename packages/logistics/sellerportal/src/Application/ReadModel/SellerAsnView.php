<?php

declare(strict_types=1);

namespace Logistics\SellerPortal\Application\ReadModel;

/**
 * Vista de un ASN (inbound) tal como la ve el seller. Forma primitiva: apta para cachear y serializar
 * a JSON sin arrastrar objetos de dominio.
 */
final readonly class SellerAsnView
{
    public function __construct(
        public string $id,
        public string $sellerId,
        public string $warehouseId,
        public string $status,
        public ?string $appointmentDate,
        public ?string $appointmentStart,
        public ?string $appointmentEnd,
    ) {}

    /**
     * Construye la vista desde una fila cruda de `inbound_asns` (stdClass de DB::table).
     */
    public static function fromRow(object $row): self
    {
        return new self(
            id: self::str($row, 'id'),
            sellerId: self::str($row, 'seller_id'),
            warehouseId: self::str($row, 'warehouse_id'),
            status: self::str($row, 'status'),
            appointmentDate: self::nullableStr($row, 'appointment_date'),
            appointmentStart: self::nullableStr($row, 'appointment_start'),
            appointmentEnd: self::nullableStr($row, 'appointment_end'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'seller_id' => $this->sellerId,
            'warehouse_id' => $this->warehouseId,
            'status' => $this->status,
            'appointment_date' => $this->appointmentDate,
            'appointment_start' => $this->appointmentStart,
            'appointment_end' => $this->appointmentEnd,
        ];
    }

    private static function str(object $row, string $field): string
    {
        $value = $row->{$field} ?? null;

        return is_scalar($value) ? (string) $value : '';
    }

    private static function nullableStr(object $row, string $field): ?string
    {
        $value = $row->{$field} ?? null;

        return is_scalar($value) ? (string) $value : null;
    }
}

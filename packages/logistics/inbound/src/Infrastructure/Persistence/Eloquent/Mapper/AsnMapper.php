<?php

declare(strict_types=1);

namespace Logistics\Inbound\Infrastructure\Persistence\Eloquent\Mapper;

use Logistics\Inbound\Domain\Model\Asn;
use Logistics\Inbound\Domain\ValueObject\AppointmentWindow;
use Logistics\Inbound\Domain\ValueObject\AsnId;
use Logistics\Inbound\Domain\ValueObject\AsnLine;
use Logistics\Inbound\Domain\ValueObject\AsnStatus;
use Logistics\Inbound\Infrastructure\Persistence\Eloquent\Model\AsnLineRecord;
use Logistics\Inbound\Infrastructure\Persistence\Eloquent\Model\AsnRecord;

/**
 * Traduce entre el agregado Asn (dominio) y su fila Eloquent + las filas de sus líneas.
 */
final class AsnMapper
{
    public function toDomain(AsnRecord $record): Asn
    {
        $lines = array_values($record->lines
            ->map(static fn (AsnLineRecord $line): AsnLine => AsnLine::of($line->sku, $line->expected_quantity))
            ->all());

        $window = null;
        if ($record->appointment_date !== null && $record->appointment_start !== null && $record->appointment_end !== null) {
            $window = AppointmentWindow::of($record->appointment_date, $record->appointment_start, $record->appointment_end);
        }

        return Asn::reconstitute(
            AsnId::fromString($record->id),
            $record->seller_id,
            $record->warehouse_id,
            $lines,
            AsnStatus::from($record->status),
            $window,
        );
    }

    /**
     * Fila de la tabla `inbound_asns`.
     *
     * @return array{id: string, seller_id: string, warehouse_id: string, status: string, appointment_date: ?string, appointment_start: ?string, appointment_end: ?string}
     */
    public function toRow(Asn $asn): array
    {
        $window = $asn->appointmentWindow();

        return [
            'id' => $asn->id()->toString(),
            'seller_id' => $asn->sellerId(),
            'warehouse_id' => $asn->warehouseId(),
            'status' => $asn->status()->value,
            'appointment_date' => $window?->date,
            'appointment_start' => $window?->startTime,
            'appointment_end' => $window?->endTime,
        ];
    }

    /**
     * Filas de la tabla `inbound_asn_lines` para un ASN (sin id: lo pone el repositorio).
     *
     * @return list<array{sku: string, expected_quantity: int}>
     */
    public function toLineRows(Asn $asn): array
    {
        return array_map(
            static fn (AsnLine $line): array => ['sku' => $line->sku, 'expected_quantity' => $line->expectedQuantity],
            $asn->lines(),
        );
    }
}

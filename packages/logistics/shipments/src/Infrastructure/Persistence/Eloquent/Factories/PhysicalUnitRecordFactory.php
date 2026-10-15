<?php

declare(strict_types=1);

namespace Logistics\Shipments\Infrastructure\Persistence\Eloquent\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Logistics\Shared\Domain\ValueObject\Uuid;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\PhysicalUnitRecord;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentLineRecord;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentRecord;

/**
 * Factory de un bulto (unidad física). Por defecto crea su envío y su línea; en uso real se pasan
 * `shipment_id` y `line_id` en el state. Por defecto, unidad 1 de 1 y pieza 1 de 1. Ids UUID v7.
 *
 * @extends Factory<PhysicalUnitRecord>
 */
final class PhysicalUnitRecordFactory extends Factory
{
    protected $model = PhysicalUnitRecord::class;

    public function definition(): array
    {
        return [
            'id' => Uuid::generate()->value,
            'shipment_id' => ShipmentRecord::factory(),
            'line_id' => ShipmentLineRecord::factory(),
            'unit_index' => 1,
            'unit_total' => 1,
            'piece_index' => 1,
            'piece_total' => 1,
        ];
    }
}

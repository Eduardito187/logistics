<?php

declare(strict_types=1);

namespace Logistics\SapConnector\Infrastructure\Persistence\Eloquent\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Logistics\SapConnector\Domain\ValueObject\MaterialMappingId;
use Logistics\SapConnector\Infrastructure\Persistence\Eloquent\MaterialMappingRecord;

/**
 * Factory del mapa de traducción sku ↔ coordenada SAP, para tests y seeders. Genera coordenadas SAP
 * realistas (material numérico, centro y almacén alfanuméricos) con identidad UUID v7 del dominio.
 *
 * @extends Factory<MaterialMappingRecord>
 */
final class MaterialMappingRecordFactory extends Factory
{
    protected $model = MaterialMappingRecord::class;

    public function definition(): array
    {
        return [
            'id' => MaterialMappingId::generate()->toString(),
            'sku' => mb_strtoupper($this->faker->unique()->bothify('SKU-####-???')),
            'sap_material' => (string) $this->faker->unique()->numberBetween(100000, 999999),
            'center' => 'C'.$this->faker->numberBetween(1000, 9999),
            'warehouse' => 'W'.$this->faker->numberBetween(10, 99),
        ];
    }
}

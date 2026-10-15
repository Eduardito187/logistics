<?php

declare(strict_types=1);

namespace Logistics\Coverage\Infrastructure\Persistence\Eloquent\Factory;

use Illuminate\Database\Eloquent\Factories\Factory;
use Logistics\Coverage\Domain\ValueObject\AdminLevel;
use Logistics\Coverage\Domain\ValueObject\GeoAreaId;
use Logistics\Coverage\Infrastructure\Persistence\Eloquent\GeoAreaRecord;

/**
 * Factory de áreas administrativas (coverage_geo_areas) para tests y seeders.
 *
 * El id es un UUID v7 tipado (regla de identidad del proyecto), no autoincremental. Por defecto crea
 * una ciudad de nivel City sin padre; los estados `department`, `province`… ajustan el nivel.
 *
 * @extends Factory<GeoAreaRecord>
 */
final class GeoAreaRecordFactory extends Factory
{
    /** @var class-string<GeoAreaRecord> */
    protected $model = GeoAreaRecord::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => GeoAreaId::generate()->toString(),
            'name' => $this->faker->unique()->city(),
            'level' => AdminLevel::City->value,
            'parent_id' => null,
        ];
    }

    public function department(): self
    {
        return $this->state(fn (): array => ['level' => AdminLevel::Department->value]);
    }

    public function province(): self
    {
        return $this->state(fn (): array => ['level' => AdminLevel::Province->value]);
    }

    public function municipality(): self
    {
        return $this->state(fn (): array => ['level' => AdminLevel::Municipality->value]);
    }
}

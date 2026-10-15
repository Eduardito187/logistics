<?php

declare(strict_types=1);

namespace Logistics\Notifications\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Logistics\Notifications\Infrastructure\Persistence\Eloquent\SmsBudgetRecord;

/**
 * Factory del contador mensual de SMS para tests y seeders.
 *
 * @extends Factory<SmsBudgetRecord>
 */
final class SmsBudgetRecordFactory extends Factory
{
    protected $model = SmsBudgetRecord::class;

    public function definition(): array
    {
        return [
            'period' => date('Y-m'),
            'sent' => $this->faker->numberBetween(0, 50),
        ];
    }
}

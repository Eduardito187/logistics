<?php

declare(strict_types=1);

namespace Logistics\Execution\Infrastructure\Console;

use Illuminate\Console\Command;
use Logistics\Execution\Database\Seeders\FailureReasonSeeder;

/**
 * Siembra los 5 motivos de entrega fallida de fábrica en cualquier entorno (no solo demo). Idempotente:
 * reutiliza el FailureReasonSeeder (existsByCode), así correrlo varias veces no duplica ni regenera UUID.
 */
final class SeedFailureReasonsCommand extends Command
{
    protected $signature = 'logistics:execution:seed-failure-reasons';

    protected $description = 'Seed the 5 built-in delivery-failure reasons (idempotent, UUID-stable).';

    public function handle(FailureReasonSeeder $seeder): int
    {
        $seeder->run();

        $this->info('Delivery-failure reasons seeded (idempotent).');

        return self::SUCCESS;
    }
}

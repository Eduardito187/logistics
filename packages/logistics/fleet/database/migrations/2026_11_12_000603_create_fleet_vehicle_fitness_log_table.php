<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bitácora append-only de la aptitud de los vehículos (auditoría #27): cuándo quedó NO APTO (por qué
 * checklist e ítems) y quién lo habilitó tras la reparación, con su nota.
 *
 * ADITIVO e idempotente. El actor va como varchar (el id de la cuenta admin es bigint, no uuid).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('fleet_vehicle_fitness_log')) {
            return;
        }

        Schema::create('fleet_vehicle_fitness_log', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('vehicle_id');
            $table->string('action', 20);
            $table->timestampTz('occurred_at');
            $table->string('reason', 1000)->nullable();
            $table->jsonb('failed_items')->nullable();
            $table->uuid('inspection_id')->nullable();
            $table->string('actor_id', 64)->nullable();
            $table->string('actor_label', 191)->nullable();
            $table->timestamps();
            $table->index(['vehicle_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fleet_vehicle_fitness_log');
    }
};

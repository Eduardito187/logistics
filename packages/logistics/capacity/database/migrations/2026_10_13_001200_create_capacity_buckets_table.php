<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('capacity_buckets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            // Scope: exactamente uno de los dos se puebla (regla del dominio, no del esquema).
            $table->uuid('node_id')->nullable()->index();
            $table->uuid('zone_id')->nullable()->index();
            $table->date('slot_date')->index();
            $table->string('time_slot');
            $table->string('unit');
            $table->decimal('total', 12, 3);
            $table->decimal('used', 12, 3);
            $table->timestamps();

            // Clave natural del cupo: un bucket por scope/fecha/franja/unidad.
            $table->unique(['node_id', 'zone_id', 'slot_date', 'time_slot', 'unit'], 'capacity_buckets_natural_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('capacity_buckets');
    }
};

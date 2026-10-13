<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fleet_vehicles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('plate')->unique();
            $table->integer('capacity_kg');
            $table->decimal('capacity_m3', 10, 3);
            $table->uuid('home_base_node_id')->index();
            $table->string('status')->index();
            $table->jsonb('documents');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fleet_vehicles');
    }
};

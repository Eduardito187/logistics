<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fleet_crews', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('driver_id')->index();
            $table->jsonb('helper_ids');
            $table->uuid('vehicle_id')->index();
            $table->date('assembled_for');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fleet_crews');
    }
};

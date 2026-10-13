<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('network_legs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('from_node_id')->index();
            $table->uuid('to_node_id')->index();
            $table->string('corridor_type')->index();
            $table->timestamps();

            // Un tramo entre dos nodos por corredor no se repite.
            $table->unique(['from_node_id', 'to_node_id', 'corridor_type'], 'network_legs_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('network_legs');
    }
};

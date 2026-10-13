<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('network_nodes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('type')->index();
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->string('status')->index();
            $table->timestamps();
        });

        // Columna geográfica para consultas espaciales (SRID 4326). Se asume que la extensión PostGIS
        // ya está instalada (init SQL). Se puebla desde lat/lng en el repositorio.
        DB::statement('ALTER TABLE network_nodes ADD COLUMN location geography(Point,4326)');
        DB::statement('CREATE INDEX network_nodes_location_gist ON network_nodes USING GIST(location)');
    }

    public function down(): void
    {
        Schema::dropIfExists('network_nodes');
    }
};

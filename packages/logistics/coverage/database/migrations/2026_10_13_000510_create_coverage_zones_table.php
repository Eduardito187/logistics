<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Zonas de cobertura: polígono PostGIS + reglas por zona.
 *
 * La columna espacial `area` y su índice GIST se crean por SQL: el schema builder de Laravel no
 * conoce los tipos de PostGIS. Se asume PostGIS ya instalado (ver ADR 003).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coverage_zones', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->uuid('parent_area_id')->nullable()->index();
            $table->jsonb('rules');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        // Geometría de polígono WGS84 (EPSG:4326) + índice espacial para ST_Contains (punto→zona).
        DB::statement('ALTER TABLE coverage_zones ADD COLUMN area geometry(Polygon,4326)');
        DB::statement('CREATE INDEX coverage_zones_area_gist ON coverage_zones USING GIST (area)');
    }

    public function down(): void
    {
        Schema::dropIfExists('coverage_zones');
    }
};

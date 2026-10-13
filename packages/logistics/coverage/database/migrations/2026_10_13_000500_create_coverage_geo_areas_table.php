<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jerarquía administrativa: Departamento > Provincia > Municipio > Ciudad.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coverage_geo_areas', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('level')->index();
            $table->uuid('parent_id')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coverage_geo_areas');
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega la ciudad de emisión de la licencia del chofer. ADITIVO y nullable: los choferes ya
 * registrados siguen válidos sin este dato y se completa luego desde el perfil (UpdateDriverProfile).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fleet_drivers', function (Blueprint $table): void {
            $table->string('license_city', 120)->nullable()->after('license_category');
        });
    }

    public function down(): void
    {
        Schema::table('fleet_drivers', function (Blueprint $table): void {
            $table->dropColumn('license_city');
        });
    }
};

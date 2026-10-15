<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enriquece a los choferes con datos personales (foto, CI, fecha de nacimiento, teléfonos, dirección,
 * categoría de licencia, contacto de emergencia) y referencias personales. Todo ADITIVO y nullable:
 * los choferes ya registrados siguen válidos sin estos datos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fleet_drivers', function (Blueprint $table): void {
            $table->string('photo_path', 1024)->nullable()->after('name');
            $table->date('birth_date')->nullable()->after('photo_path');
            $table->string('national_id', 40)->nullable()->after('birth_date');
            $table->string('phone_primary', 40)->nullable()->after('national_id');
            $table->string('phone_secondary', 40)->nullable()->after('phone_primary');
            $table->text('address')->nullable()->after('phone_secondary');
            $table->string('license_category', 20)->nullable()->after('license_expiry');
            $table->string('emergency_contact_name', 160)->nullable()->after('license_category');
            $table->string('emergency_contact_phone', 40)->nullable()->after('emergency_contact_name');
            // Referencias personales: lista de {name, relationship, phone, note} (misma idea que documents).
            $table->jsonb('references')->default('[]')->after('emergency_contact_phone');
        });
    }

    public function down(): void
    {
        Schema::table('fleet_drivers', function (Blueprint $table): void {
            $table->dropColumn([
                'photo_path',
                'birth_date',
                'national_id',
                'phone_primary',
                'phone_secondary',
                'address',
                'license_category',
                'emergency_contact_name',
                'emergency_contact_phone',
                'references',
            ]);
        });
    }
};

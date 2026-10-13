<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enriquece a los ayudantes con datos personales (foto, CI, fecha de nacimiento, teléfono secundario,
 * dirección, contacto de emergencia) y referencias personales. El `phone` existente es el teléfono
 * primario del ayudante. Todo ADITIVO y nullable: los ayudantes ya registrados siguen válidos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fleet_helpers', function (Blueprint $table): void {
            $table->string('photo_path', 1024)->nullable()->after('name');
            $table->date('birth_date')->nullable()->after('photo_path');
            $table->string('national_id', 40)->nullable()->after('birth_date');
            $table->string('phone_secondary', 40)->nullable()->after('phone');
            $table->text('address')->nullable()->after('phone_secondary');
            $table->string('emergency_contact_name', 160)->nullable()->after('address');
            $table->string('emergency_contact_phone', 40)->nullable()->after('emergency_contact_name');
            // Referencias personales: lista de {name, relationship, phone, note} (misma idea que documents).
            $table->jsonb('references')->default('[]')->after('emergency_contact_phone');
        });
    }

    public function down(): void
    {
        Schema::table('fleet_helpers', function (Blueprint $table): void {
            $table->dropColumn([
                'photo_path',
                'birth_date',
                'national_id',
                'phone_secondary',
                'address',
                'emergency_contact_name',
                'emergency_contact_phone',
                'references',
            ]);
        });
    }
};

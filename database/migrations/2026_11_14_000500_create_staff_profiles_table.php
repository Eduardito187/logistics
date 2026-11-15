<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Datos PERSONALES de cualquier usuario de la app (tienda, preparador, supervisor, admin, chofer…): teléfono,
 * documento, foto de perfil, dirección y contacto de emergencia. Antes solo los choferes los tenían (en su
 * ficha de flota); el resto de los roles no tenía dónde guardarlos. Una fila por usuario. Aditiva e idempotente.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('staff_profiles')) {
            return;
        }

        Schema::create('staff_profiles', function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id')->primary();
            $table->string('phone', 40)->nullable();
            $table->string('phone_secondary', 40)->nullable();
            $table->string('national_id', 40)->nullable();
            $table->date('birth_date')->nullable();
            $table->string('address', 500)->nullable();
            $table->string('emergency_contact_name', 160)->nullable();
            $table->string('emergency_contact_phone', 40)->nullable();
            $table->string('photo_disk', 60)->nullable();
            $table->string('photo_path', 1024)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_profiles');
    }
};

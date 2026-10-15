<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_permissions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            // Clave estable "<grupo>.<accion>" (o "*"); es el contrato de texto del RBAC.
            $table->string('key')->unique();
            $table->string('group')->index();
            $table->string('action');
            $table->text('description')->nullable();
            // Permiso de sistema: parte del catálogo base, no editable desde el admin.
            $table->boolean('is_system')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_permissions');
    }
};

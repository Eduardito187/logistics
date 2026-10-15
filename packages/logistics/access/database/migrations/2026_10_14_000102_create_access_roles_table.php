<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_roles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            // Slug estable del rol; es la referencia con la que los usuarios declaran sus roles.
            $table->string('key')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            // Rol de sistema: parte del catálogo base, no eliminable.
            $table->boolean('is_system')->default(false);
            // Conjunto de claves de permiso concedidas (o ["*"]).
            $table->jsonb('permission_keys');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_roles');
    }
};

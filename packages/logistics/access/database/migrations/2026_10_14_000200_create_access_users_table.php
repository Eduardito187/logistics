<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password_hash');
            $table->string('status')->index();
            // Claves de rol asignadas al usuario (slugs). La resolución a permisos la hace el orquestador.
            $table->jsonb('role_keys');
            // Instante del último login exitoso. Nulo mientras el usuario no haya iniciado sesión.
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_users');
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Credenciales por canal para `/api/v1/channels/*` con su rate limit (P1-AUTH-04).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_channels', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('key')->unique();
            // Solo se guarda el hash del secreto (cast 'hashed' en el modelo).
            $table->string('secret');
            $table->unsignedInteger('rate_limit_per_minute')->default(60);
            $table->boolean('active')->default(true);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index('active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_channels');
    }
};

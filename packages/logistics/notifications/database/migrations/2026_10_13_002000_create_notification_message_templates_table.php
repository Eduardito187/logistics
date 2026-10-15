<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plantillas de mensaje por tipo, canal y locale (P1-NOT-08). El cuerpo y el asunto llevan variables
 * `{{clave}}` que se reemplazan al renderizar con los datos del envío. Única por (tipo, canal, locale).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_message_templates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('message_type');
            $table->string('channel');
            $table->string('locale', 8)->default('es');
            $table->string('subject')->default('');
            $table->text('body');
            $table->timestamps();

            $table->unique(['message_type', 'channel', 'locale']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_message_templates');
    }
};

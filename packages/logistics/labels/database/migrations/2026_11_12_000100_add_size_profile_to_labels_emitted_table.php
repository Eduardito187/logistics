<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Perfil de tamaño de la etiqueta (roadmap 03 — Etiquetas Lite/chicas).
 *
 * Guarda el perfil con el que se renderizó el ZPL de cada etiqueta: 'standard' (100x75, histórico), 'lite'
 * (50x25), 'lite_40x30' o 'micro'. El tamaño es PRESENTACIÓN, no identidad (el `label_id` y el QR firmado
 * no cambian): sirve para saber a qué tamaño se emitió y para que la reimpresión mantenga ese tamaño.
 *
 * ADITIVA e idempotente: nullable con default 'standard' (= comportamiento histórico), no reescribe filas
 * existentes y no corre si la columna ya existe.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('labels_emitted') || Schema::hasColumn('labels_emitted', 'size_profile')) {
            return;
        }

        Schema::table('labels_emitted', function (Blueprint $table): void {
            $table->string('size_profile', 20)->default('standard')->after('zpl');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('labels_emitted') && Schema::hasColumn('labels_emitted', 'size_profile')) {
            Schema::table('labels_emitted', function (Blueprint $table): void {
                $table->dropColumn('size_profile');
            });
        }
    }
};

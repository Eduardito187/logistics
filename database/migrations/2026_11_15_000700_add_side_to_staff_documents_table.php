<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cara del documento del personal: `front` (anverso) | `back` (reverso) | null (documento de una sola cara).
 *
 * El carnet y la licencia se suben en DOS archivos con el mismo `document_type` y `reference`; hasta ahora la
 * cara viajaba en `note` como texto libre ("Anverso" / "Reverso"). Con esta columna la cara es un dato propio
 * y `note` queda para observaciones. No se reescriben las filas existentes: quedan en null.
 *
 * ADITIVA, nullable e idempotente (no falla si la tabla no existe todavía o si la columna ya está).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('staff_documents') || Schema::hasColumn('staff_documents', 'side')) {
            return;
        }

        Schema::table('staff_documents', function (Blueprint $table): void {
            $table->string('side', 10)->nullable();
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('staff_documents') || !Schema::hasColumn('staff_documents', 'side')) {
            return;
        }

        Schema::table('staff_documents', function (Blueprint $table): void {
            $table->dropColumn('side');
        });
    }
};

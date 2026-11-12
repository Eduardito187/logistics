<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vínculo real entre el POD y las evidencias ya subidas por la app: todas las fotos (paths del disco de
 * evidencia) y los ids de evidencia anti-fraude (firma/documento). JSON nullable. ADITIVA e idempotente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('execution_deliveries', function (Blueprint $table): void {
            if (!Schema::hasColumn('execution_deliveries', 'pod_photo_paths')) {
                $table->jsonb('pod_photo_paths')->nullable();
            }
            if (!Schema::hasColumn('execution_deliveries', 'pod_evidence_ids')) {
                $table->jsonb('pod_evidence_ids')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('execution_deliveries', function (Blueprint $table): void {
            foreach (['pod_photo_paths', 'pod_evidence_ids'] as $column) {
                if (Schema::hasColumn('execution_deliveries', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};

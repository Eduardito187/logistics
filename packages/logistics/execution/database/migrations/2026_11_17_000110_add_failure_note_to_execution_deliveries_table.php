<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nota libre opcional del intento de entrega fallido (el chofer la escribe cuando el motivo la exige o para
 * dar contexto). Aditiva e idempotente: solo agrega la columna si la tabla existe y la columna no.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('execution_deliveries')) {
            return;
        }

        if (Schema::hasColumn('execution_deliveries', 'failure_note')) {
            return;
        }

        Schema::table('execution_deliveries', function (Blueprint $table): void {
            $table->text('failure_note')->nullable()->after('failure_reason');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('execution_deliveries') && Schema::hasColumn('execution_deliveries', 'failure_note')) {
            Schema::table('execution_deliveries', function (Blueprint $table): void {
                $table->dropColumn('failure_note');
            });
        }
    }
};

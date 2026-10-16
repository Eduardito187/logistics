<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega los contactos de respaldo del destinatario a la tabla de notificaciones. El canal primario
 * (push FCM) va por el topic del envío y no necesita contacto; email y teléfono son los
 * respaldos de la cascada (email y SMS crítico), opcionales y sin PII más allá de lo imprescindible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table): void {
            $table->string('email')->nullable()->after('attempts');
            $table->string('phone', 32)->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table): void {
            $table->dropColumn(['email', 'phone']);
        });
    }
};

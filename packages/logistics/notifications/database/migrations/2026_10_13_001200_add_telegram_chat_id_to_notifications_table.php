<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega el chat id de Telegram del destinatario a la tabla de notificaciones. Es el contacto de
 * respaldo del canal Telegram (como email y phone lo son de email/SMS/WhatsApp): opcional, se captura
 * cuando el cliente vincula el bot de Telegram con su pedido.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table): void {
            $table->string('telegram_chat_id', 64)->nullable()->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table): void {
            $table->dropColumn('telegram_chat_id');
        });
    }
};

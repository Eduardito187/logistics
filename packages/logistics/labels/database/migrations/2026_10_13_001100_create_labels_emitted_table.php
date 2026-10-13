<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('labels_emitted', function (Blueprint $table): void {
            // pk propia de almacenamiento (uuid), independiente del id de negocio de la etiqueta.
            $table->uuid('id')->primary();
            $table->string('order_id')->index();
            // label_id = campo `id` del payload: único por bulto físico → detección de duplicados.
            $table->string('label_id')->unique();
            $table->unsignedInteger('key_id');
            $table->text('zpl');
            $table->text('signed_json');
            $table->timestampTz('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('labels_emitted');
    }
};

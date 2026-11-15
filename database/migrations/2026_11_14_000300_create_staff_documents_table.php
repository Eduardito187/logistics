<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Documentos del PERSONAL (cualquier rol: chofer, ayudante, tienda, preparador, supervisor…) subidos desde la
 * ficha del personal de la app. Los documentos de flota (`fleet_documents`) pertenecen a un chofer, ayudante
 * o vehículo; esta tabla cubre a cualquier usuario, tenga o no ficha de chofer. Aditiva e idempotente.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('staff_documents')) {
            return;
        }

        Schema::create('staff_documents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('document_type', 60);
            $table->string('reference')->nullable();
            $table->date('issued_on')->nullable();
            $table->date('expires_on')->nullable()->index();
            $table->string('file_disk', 60);
            $table->string('file_path', 1024);
            $table->string('mime_type', 120)->nullable();
            $table->string('note', 1000)->nullable();
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_documents');
    }
};

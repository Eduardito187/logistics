<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla de documentos de flota con vencimiento y archivo en S3: SOAT, inspección técnica, pólizas,
 * carnés de salud, etc., asociados a un vehículo, chofer o ayudante.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fleet_documents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('owner_type')->index();
            $table->uuid('owner_id')->index();
            $table->string('document_type');
            $table->date('expires_on')->index();
            $table->date('issued_on')->nullable();
            // Clave del objeto en el disco S3 (no la URL): se firma al vuelo al descargar.
            $table->string('file_path')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fleet_documents');
    }
};

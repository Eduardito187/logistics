<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega la referencia del documento de flota (nº de póliza/serie del SOAT o nº de certificado de la
 * inspección técnica). ADITIVO y nullable: los documentos ya registrados siguen válidos sin este dato.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fleet_documents', function (Blueprint $table): void {
            $table->string('reference', 80)->nullable()->after('file_path');
        });
    }

    public function down(): void
    {
        Schema::table('fleet_documents', function (Blueprint $table): void {
            $table->dropColumn('reference');
        });
    }
};

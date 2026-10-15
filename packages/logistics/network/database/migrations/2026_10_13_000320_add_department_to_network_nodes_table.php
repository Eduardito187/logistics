<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Agrega el departamento del nodo (uno de los nueve de Bolivia). Es nullable: un nodo importado de
     * SAP puede llegar sin él y asignarse después. Se indexa porque las consultas de corredores
     * operativos filtran por departamento.
     */
    public function up(): void
    {
        Schema::table('network_nodes', function (Blueprint $table): void {
            $table->string('department')->nullable()->index()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('network_nodes', function (Blueprint $table): void {
            $table->dropColumn('department');
        });
    }
};

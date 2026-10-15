<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Índice de escala para la configuración versionada (frente E — escala). Aditivo: solo acelera lectura.
 *
 *  - (key, effective_from): `currentFor` resuelve la versión vigente de una clave a un instante dado
 *    (`key = ? AND effective_from <= ahora ORDER BY version DESC`). El único (key, version) sirve el
 *    orden, pero no el rango sobre `effective_from`; este compuesto acota directamente por clave y por
 *    el corte temporal, que es el acceso más caliente (resolver reglas vigentes en cada decisión).
 *
 * `key`, `effective_from` y el único (key, version) ya existen desde la creación.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings_versions', function (Blueprint $table): void {
            $table->index(['key', 'effective_from'], 'settings_versions_key_effective_from_index');
        });
    }

    public function down(): void
    {
        Schema::table('settings_versions', function (Blueprint $table): void {
            $table->dropIndex('settings_versions_key_effective_from_index');
        });
    }
};

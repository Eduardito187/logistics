<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Datos del cliente por dispositivo para distinguir teléfonos en `GET /app/devices` (auditoría app #28):
 * modelo del equipo, versión del sistema operativo y versión/build de la app instalada.
 *
 * Los llena la guarda de la puerta app (middleware `EnsureDeviceToken`) a partir de las
 * cabeceras que envía la app (`X-App-Version`, `X-App-Build`, `X-Device-Model`, `X-Device-Os-Version`),
 * en el mismo UPDATE de `last_seen_at` (sin escrituras extra en el camino caliente).
 *
 * ADITIVA, nullable e idempotente: cada columna se agrega solo si no existe.
 */
return new class extends Migration
{
    /** @var list<string> */
    private array $columns = ['model', 'os_version', 'app_version', 'app_build'];

    public function up(): void
    {
        $missing = array_values(array_filter(
            $this->columns,
            static fn (string $column): bool => !Schema::hasColumn('devices', $column),
        ));

        if ($missing === []) {
            return;
        }

        Schema::table('devices', function (Blueprint $table) use ($missing): void {
            foreach ($missing as $column) {
                // Texto corto libre que reporta el cliente (p. ej. "Samsung SM-A515F", "Android 13", "1.4.2", "42").
                $table->string($column, 120)->nullable();
            }
        });
    }

    public function down(): void
    {
        $present = array_values(array_filter(
            $this->columns,
            static fn (string $column): bool => Schema::hasColumn('devices', $column),
        ));

        if ($present === []) {
            return;
        }

        Schema::table('devices', function (Blueprint $table) use ($present): void {
            $table->dropColumn($present);
        });
    }
};

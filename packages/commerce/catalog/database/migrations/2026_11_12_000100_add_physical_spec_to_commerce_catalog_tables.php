<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Medidas físicas en el catálogo (Roadmap 02, Fase 2.0): peso (kg) y largo/ancho/alto (cm) en el
 * producto y, como valores por defecto, en la categoría (escalón intermedio de la cascada
 * producto → categoría → default global).
 *
 * Aditiva e idempotente: solo agrega columnas nullable que falten (guardas hasTable/hasColumn); no toca
 * datos existentes. El volumen NO se persiste: se deriva de L×A×H en el dominio (PhysicalSpec).
 */
return new class extends Migration
{
    /** @var list<string> */
    private const TABLES = ['commerce_products', 'commerce_categories'];

    public function up(): void
    {
        foreach (self::TABLES as $tableName) {
            if (!Schema::hasTable($tableName)) {
                continue;
            }

            $columns = [
                'weight_kg' => !Schema::hasColumn($tableName, 'weight_kg'),
                'length_cm' => !Schema::hasColumn($tableName, 'length_cm'),
                'width_cm' => !Schema::hasColumn($tableName, 'width_cm'),
                'height_cm' => !Schema::hasColumn($tableName, 'height_cm'),
            ];

            if (!in_array(true, $columns, true)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($columns): void {
                if ($columns['weight_kg']) {
                    $table->decimal('weight_kg', 10, 3)->nullable();
                }
                if ($columns['length_cm']) {
                    $table->decimal('length_cm', 8, 2)->nullable();
                }
                if ($columns['width_cm']) {
                    $table->decimal('width_cm', 8, 2)->nullable();
                }
                if ($columns['height_cm']) {
                    $table->decimal('height_cm', 8, 2)->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $tableName) {
            if (!Schema::hasTable($tableName)) {
                continue;
            }

            $existing = array_values(array_filter(
                ['weight_kg', 'length_cm', 'width_cm', 'height_cm'],
                static fn (string $column): bool => Schema::hasColumn($tableName, $column),
            ));

            if ($existing !== []) {
                Schema::table($tableName, function (Blueprint $table) use ($existing): void {
                    $table->dropColumn($existing);
                });
            }
        }
    }
};

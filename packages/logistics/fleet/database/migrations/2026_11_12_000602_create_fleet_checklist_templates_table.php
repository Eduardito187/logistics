<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Plantillas del checklist pre-salida (auditoría #27): los ítems, su categoría, criticidad y si exigen
 * foto viven en el servidor y se administran en el admin (antes estaban fijos en la app).
 *
 * ADITIVO e idempotente: crea la tabla solo si no existe y siembra la plantilla por defecto (los 10 ítems
 * que la app tenía fijos, con frenos y motor como CRÍTICOS) solo si la tabla está vacía. Así funciona en
 * un docker nuevo sin depender de seeders.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('fleet_checklist_templates')) {
            Schema::create('fleet_checklist_templates', static function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('name', 120);
                // null = plantilla por defecto de la flota; un valor = específica de ese tipo de vehículo.
                $table->string('vehicle_type', 40)->nullable();
                $table->boolean('active')->default(true);
                $table->jsonb('items');
                $table->timestamps();
                $table->index(['active', 'vehicle_type']);
            });
        }

        if (DB::table('fleet_checklist_templates')->exists()) {
            return;
        }

        $items = [
            ['key' => 'engine', 'label' => 'Motor: ruidos y funcionamiento', 'category' => 'mechanical', 'criticality' => 'critical', 'requires_photo' => false],
            ['key' => 'oil', 'label' => 'Aceite de motor', 'category' => 'fluids', 'criticality' => 'major', 'requires_photo' => false],
            ['key' => 'coolant', 'label' => 'Agua / refrigerante', 'category' => 'fluids', 'criticality' => 'major', 'requires_photo' => false],
            ['key' => 'brakes', 'label' => 'Frenos', 'category' => 'safety', 'criticality' => 'critical', 'requires_photo' => false],
            ['key' => 'tires', 'label' => 'Llantas y presión', 'category' => 'tires', 'criticality' => 'major', 'requires_photo' => false],
            ['key' => 'lights', 'label' => 'Luces y direccionales', 'category' => 'electrical', 'criticality' => 'major', 'requires_photo' => false],
            ['key' => 'leaks', 'label' => 'Fugas (aceite, combustible, agua)', 'category' => 'mechanical', 'criticality' => 'major', 'requires_photo' => false],
            ['key' => 'fuel', 'label' => 'Nivel de combustible', 'category' => 'fluids', 'criticality' => 'minor', 'requires_photo' => false],
            ['key' => 'extinguisher', 'label' => 'Extintor', 'category' => 'safety', 'criticality' => 'major', 'requires_photo' => false],
            ['key' => 'documents', 'label' => 'Documentos del vehículo', 'category' => 'documents', 'criticality' => 'minor', 'requires_photo' => false],
        ];

        DB::table('fleet_checklist_templates')->insert([
            'id' => (string) Str::uuid7(),
            'name' => 'Default pre-trip checklist',
            'vehicle_type' => null,
            'active' => true,
            'items' => json_encode($items, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('fleet_checklist_templates');
    }
};

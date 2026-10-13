<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carrier_tariffs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            // Referencia al transportista por su id como texto (sin FK dura: contexto desacoplable).
            $table->uuid('carrier_id')->index();
            // Corredor origen→destino + tipo, en el mismo vocabulario que network pero como texto.
            $table->string('corridor_from');
            $table->string('corridor_to');
            $table->string('corridor_type');
            $table->string('vehicle_class');
            // Precio guardado en centavos (entero) para evitar errores de redondeo.
            $table->integer('price_cents');
            $table->string('currency');
            $table->date('effective_from');
            $table->timestamps();

            // Índice por corredor: acelera la búsqueda de la tarifa más barata por corredor.
            $table->index(['corridor_from', 'corridor_to', 'corridor_type'], 'carrier_tariffs_corridor_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carrier_tariffs');
    }
};

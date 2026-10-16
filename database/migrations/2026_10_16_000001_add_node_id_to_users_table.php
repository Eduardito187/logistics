<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vincula una cuenta de usuario (login de la app) con un nodo de tipo tienda.
 *
 * Regla de negocio: un usuario con rol tienda pertenece a UN nodo de tipo `store`. El usuario
 * autenticado (`App\Models\User`, Sanctum) vive en Access/identidad y el nodo (`network_nodes`, UUID)
 * vive en el contexto Network: son bounded contexts distintos.
 *
 * Guardamos la referencia por VALOR (el UUID del nodo), SIN clave foránea dura, igual que
 * `users.driver_id` referencia al chofer de Fleet. Así cada contexto sigue siendo extraíble a su
 * propio servicio. ADITIVO y nullable: las cuentas que no son de tienda (chofer, supervisor, etc.)
 * lo dejan en NULL y siguen válidas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // UUID del nodo de Network (network_nodes.id, type='store'). Sin FK: Network es otro contexto.
            $table->uuid('node_id')->nullable()->after('driver_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('node_id');
        });
    }
};

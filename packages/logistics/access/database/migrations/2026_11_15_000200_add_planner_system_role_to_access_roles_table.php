<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Alta del rol de sistema «planificador» en el catálogo de roles del contexto Access.
 *
 * El resto de los roles de sistema entran por el AccessSeeder (paso manual `db:seed`). Para que el rol
 * nuevo exista en cualquier entorno sin pasos manuales, esta migración lo agrega al correr `migrate`.
 *
 * Es aditiva e idempotente: solo inserta si la tabla existe y todavía no hay un rol con esa clave; nunca
 * pisa un rol existente (si alguien ya lo creó o editó sus permisos desde el admin, se respeta). El
 * AccessSeeder, cuando corre, deja la misma fila (misma clave, mismos permisos derivados de
 * `App\Enums\Role::Planificador`), así que ambos caminos convergen.
 */
return new class extends Migration
{
    private const ROLE_KEY = 'planificador';

    /**
     * Claves de permiso del rol (forma con punto del catálogo Access). Es una foto del momento de la
     * migración: la fuente de verdad en ejecución sigue siendo `App\Enums\Role::abilities()`.
     *
     * @var list<string>
     */
    private const PERMISSION_KEYS = [
        'shipments.read',
        'shipments.write',
        'planning.read',
        'planning.write',
        'fleet.read',
        'network.read',
        'capacity.read',
        'capacity.write',
        'execution.read',
        'execution.write',
    ];

    public function up(): void
    {
        if (!Schema::hasTable('access_roles')) {
            return;
        }

        if (DB::table('access_roles')->where('key', self::ROLE_KEY)->exists()) {
            return;
        }

        $now = now();

        DB::table('access_roles')->insert([
            'id' => (string) Str::uuid7(),
            'key' => self::ROLE_KEY,
            'name' => 'Planner',
            'description' => 'Planner role',
            'is_system' => true,
            'permission_keys' => json_encode(self::PERMISSION_KEYS, JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        // Sin reversa destructiva: el rol puede estar ya asignado a usuarios. Quitarlo es una decisión
        // de administración (desde el admin), no de un rollback.
    }
};

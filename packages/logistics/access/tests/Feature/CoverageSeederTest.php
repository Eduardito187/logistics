<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Logistics\Access\Infrastructure\Seeder\AccessSeeder;

/**
 * Cobertura del seeder de infraestructura del contexto Access: debe sembrar el catálogo base de RBAC
 * (permisos y roles de sistema) y el usuario administrador de demo sin reventar, y ser idempotente.
 * Corre contra PostgreSQL (logistics_test); skip sin él.
 */
it('siembra el catálogo RBAC y el admin de demo sin reventar', function (): void {
    $this->seed(AccessSeeder::class);

    expect(DB::table('access_permissions')->count())->toBeGreaterThan(0)
        ->and(DB::table('access_roles')->count())->toBeGreaterThan(0)
        ->and(DB::table('access_users')->where('email', 'admin@dismac.test')->count())->toBe(1);
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');

it('es idempotente: correrlo dos veces no duplica filas', function (): void {
    $this->seed(AccessSeeder::class);
    $permisos = DB::table('access_permissions')->count();
    $roles = DB::table('access_roles')->count();

    $this->seed(AccessSeeder::class);

    expect(DB::table('access_permissions')->count())->toBe($permisos)
        ->and(DB::table('access_roles')->count())->toBe($roles)
        ->and(DB::table('access_users')->where('email', 'admin@dismac.test')->count())->toBe(1);
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');

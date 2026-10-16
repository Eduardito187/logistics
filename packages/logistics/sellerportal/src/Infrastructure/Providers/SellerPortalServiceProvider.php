<?php

declare(strict_types=1);

namespace Logistics\SellerPortal\Infrastructure\Providers;

use Illuminate\Support\ServiceProvider;
use Logistics\SellerPortal\Application\Contract\SellerContextResolver;
use Logistics\SellerPortal\Application\Contract\SellerReadPort;
use Logistics\SellerPortal\Infrastructure\Identity\PermissionScopedSellerContextResolver;
use Logistics\SellerPortal\Infrastructure\Persistence\DatabaseSellerReadPort;

/**
 * Registro del package Seller Portal (Fase 5, ADR 017): portal/API de autoservicio del seller, solo lectura.
 *
 * Se registra explícitamente en bootstrap/providers.php (el package va en dont-discover) para que el
 * orden lo controle el proyecto: debe bootear DESPUÉS de AccessServiceProvider, que aporta los alias de
 * middleware `access.token` / `access.permission` que protegen estas rutas.
 *
 * No carga migraciones (solo lee) ni registra handlers CQRS: es una capa de presentación delgada que
 * agrega lecturas de otros contextos filtradas por el seller del token.
 */
final class SellerPortalServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Puerto de lectura: lee las tablas read-only de inbound/inventory/settlement acotadas por seller.
        $this->app->bind(SellerReadPort::class, DatabaseSellerReadPort::class);

        // Resolución del seller del token (hoy por permiso de scope; ver README para el claim real).
        $this->app->bind(SellerContextResolver::class, PermissionScopedSellerContextResolver::class);
    }

    public function boot(): void
    {
        // Sin migraciones, vistas ni handlers propios: todo el I/O es lectura de tablas ajenas.
    }
}

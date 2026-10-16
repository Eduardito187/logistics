<?php

declare(strict_types=1);

namespace Logistics\Shared\Presentation\Filament\Concerns;

/**
 * Restringe un Resource/Page de Filament a los roles de OFICINA: administrador y supervisor.
 *
 * Es el alcance operativo global del backoffice: datos maestros, flota, red, comercio y la
 * planificación que solo gestiona la oficina. Los roles de campo (chofer, preparador, tienda,
 * transportista) no ven estas secciones; su navegación se gatea aparte, a lo mínimo que cada uno opera.
 *
 * Sobrescribir `canAccess()` basta: Filament usa ese único resorte tanto para ocultar el recurso del
 * menú como para bloquear el acceso directo por URL (403).
 */
trait OfficeOnly
{
    use ResolvesUserRoles;

    public static function canAccess(): bool
    {
        return static::authUserHasAnyRoleValue('admin', 'supervisor');
    }
}

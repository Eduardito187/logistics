<?php

declare(strict_types=1);

namespace Logistics\Shared\Presentation\Filament\Concerns;

/**
 * Restringe un Resource/Page de Filament a la OFICINA DE PLANIFICACIÓN: administrador, supervisor y
 * planificador.
 *
 * Es el alcance de las secciones que el planificador necesita para armar los viajes (calendario de
 * despacho, cupos, envíos y sus productos). Lo demás del backoffice sigue con {@see OfficeOnly}
 * (administrador y supervisor). Los roles de campo no ven estas secciones.
 *
 * Sobrescribir `canAccess()` basta: Filament usa ese único resorte tanto para ocultar el recurso del
 * menú como para bloquear el acceso directo por URL (403).
 *
 * Donde el planificador solo CONSULTA, el recurso gatea sus escrituras con `canWriteAsOffice()`
 * (administrador y supervisor).
 */
trait PlanningOffice
{
    use ResolvesUserRoles;

    public static function canAccess(): bool
    {
        return static::authUserHasAnyRoleValue('admin', 'supervisor', 'planificador');
    }

    /**
     * ¿La cuenta autenticada puede ESCRIBIR como oficina (administrador o supervisor)? El planificador
     * entra al recurso pero no pasa esta puerta. Público para que las páginas y los managers de relaciones
     * del recurso gateen sus propias acciones con la misma regla.
     */
    public static function canWriteAsOffice(): bool
    {
        return static::authUserHasAnyRoleValue('admin', 'supervisor');
    }
}

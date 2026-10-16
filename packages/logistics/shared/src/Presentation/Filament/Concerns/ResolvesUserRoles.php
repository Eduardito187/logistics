<?php

declare(strict_types=1);

namespace Logistics\Shared\Presentation\Filament\Concerns;

use BackedEnum;
use Illuminate\Support\Facades\Auth;

/**
 * Base de gating por rol para la navegación del admin Filament.
 *
 * Expone un único helper que responde "¿la cuenta autenticada tiene alguno de estos roles?",
 * comparando el VALOR string del rol (p. ej. "admin", "supervisor", "chofer"). Se compara por string a
 * propósito: así cada package de dominio gatea su navegación SIN acoplarse a la capa de aplicación (no
 * hace `use App\Enums\Role` ni `use App\Models\User`), igual que el trait AdminOnly del contexto access.
 * Funciona con cualquier principal que exponga `roles(): iterable` de enums respaldados (BackedEnum),
 * como el User del backoffice (trait HasRoles). Mantiene cada contexto extraíble a microservicio.
 */
trait ResolvesUserRoles
{
    /**
     * ¿El usuario autenticado tiene al menos uno de estos valores de rol?
     */
    protected static function authUserHasAnyRoleValue(string ...$roleValues): bool
    {
        $user = Auth::user();

        if ($user === null || !method_exists($user, 'roles')) {
            return false;
        }

        foreach ($user->roles() as $role) {
            if ($role instanceof BackedEnum && in_array($role->value, $roleValues, true)) {
                return true;
            }
        }

        return false;
    }
}

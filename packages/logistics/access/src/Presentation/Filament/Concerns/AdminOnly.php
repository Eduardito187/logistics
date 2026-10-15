<?php

declare(strict_types=1);

namespace Logistics\Access\Presentation\Filament\Concerns;

use BackedEnum;
use Illuminate\Support\Facades\Auth;

/**
 * Restringe un Resource de Filament a cuentas con rol de administrador.
 *
 * El módulo de acceso (permisos, roles, usuarios y tokens) es la gestión de seguridad de la
 * plataforma: solo lo maneja un administrador, no un supervisor. La comprobación lee los roles de la
 * cuenta autenticada y acepta si alguno vale "admin".
 *
 * Se compara el rol por su valor string a propósito: así este package de dominio no se acopla a la
 * capa de aplicación (no hace `use App\Enums\Role` ni `use App\Models\User`). Funciona con cualquier
 * principal que exponga `roles(): iterable` de enums respaldados (BackedEnum), como el User del backoffice.
 */
trait AdminOnly
{
    public static function canAccess(): bool
    {
        $user = Auth::user();

        if ($user === null || !method_exists($user, 'roles')) {
            return false;
        }

        foreach ($user->roles() as $role) {
            if ($role instanceof BackedEnum && $role->value === 'admin') {
                return true;
            }
        }

        return false;
    }
}

<?php

declare(strict_types=1);

use App\Enums\Ability;
use App\Enums\Role;
use App\Models\User;

// Ejercita el enum de roles (etiquetas, habilidades por rol y opciones de select) y el trait HasRoles
// del usuario (asignar/quitar roles de forma idempotente y derivar sus habilidades). Cubre cada rama
// del match de abilities() recorriendo todos los casos del enum.

it('expone una etiqueta en inglés para cada rol', function (): void {
    foreach (Role::cases() as $role) {
        expect($role->label())->toBeString()->not->toBe('');
    }
});

it('concede habilidades por rol, con comodín para el administrador', function (): void {
    foreach (Role::cases() as $role) {
        $abilities = $role->abilities();
        expect($abilities)->toBeArray()->not->toBe([]);
    }

    expect(Role::Admin->abilities())->toBe([Ability::WILDCARD])
        ->and(Role::Supervisor->abilities())->toContain(Ability::PLANNING_WRITE);
});

it('arma las opciones de select valor => etiqueta', function (): void {
    $options = Role::options();

    expect($options)->toHaveKey(Role::Admin->value)
        ->and($options[Role::Admin->value])->toBe('Administrator')
        ->and($options)->toHaveCount(count(Role::cases()));
});

it('asigna roles de forma idempotente y los lee como enums', function (): void {
    $user = User::factory()->create();

    $user->assignRole(Role::Supervisor);
    $user->assignRole(Role::Supervisor); // idempotente: no duplica

    expect($user->hasRole(Role::Supervisor))->toBeTrue()
        ->and($user->hasRole(Role::Admin))->toBeFalse()
        ->and($user->hasAnyRole(Role::Admin, Role::Supervisor))->toBeTrue()
        ->and($user->roles())->toHaveCount(1)
        ->and($user->roles()[0])->toBe(Role::Supervisor);
});

it('quita un rol y recalcula las habilidades del usuario', function (): void {
    $user = User::factory()->create();
    $user->assignRole(Role::Chofer);

    expect($user->abilities())->toContain(Ability::EXECUTION_WRITE);

    $user->removeRole(Role::Chofer);
    expect($user->hasRole(Role::Chofer))->toBeFalse()
        ->and($user->abilities())->toBe([]);
});

it('colapsa a comodín las habilidades de un administrador', function (): void {
    $user = User::factory()->create();
    $user->assignRole(Role::Admin);
    $user->assignRole(Role::Supervisor);

    // Si algún rol concede '*', abilities() devuelve solo el comodín (acceso total).
    expect($user->abilities())->toBe([Ability::WILDCARD]);
});

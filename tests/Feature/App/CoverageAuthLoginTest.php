<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\User;

/**
 * Cobertura del login propio del SPA (LoginController): la pantalla de ingreso de invitado, el alta de
 * sesión con credenciales correctas e incorrectas y el cierre de sesión. Las rutas viven en
 * routes/app-guest/auth.php (login) y routes/app/auth.php (logout).
 */
// Las rutas del SPA son web (grupo con CSRF). Para los POST se pasa un `_token` que coincide en sesión y
// cuerpo: así se satisface CSRF SIN desactivar middleware, conservando la sesión que necesitan las
// aserciones de errores de validación y de autenticación.
$csrf = 'test-csrf-token';

it('muestra el formulario de ingreso a un invitado', function (): void {
    $this->get('/app/login')->assertOk();
});

it('autentica con credenciales correctas y redirige al panel', function () use ($csrf): void {
    $user = User::factory()->create(['password' => bcrypt('password')]);
    $user->assignRole(Role::Admin);

    $this->withSession(['_token' => $csrf])
        ->post('/app/login', [
            'email' => $user->email,
            'password' => 'password',
            'remember' => true,
            '_token' => $csrf,
        ])
        ->assertRedirect(route('app.dashboard'));

    $this->assertAuthenticatedAs($user);
});

it('rechaza credenciales incorrectas con error de validación', function () use ($csrf): void {
    $user = User::factory()->create(['password' => bcrypt('password')]);

    $this->withSession(['_token' => $csrf])
        ->post('/app/login', [
            'email' => $user->email,
            'password' => 'clave-incorrecta',
            '_token' => $csrf,
        ])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

it('cierra la sesión del guard web', function () use ($csrf): void {
    $user = User::factory()->create();
    $user->assignRole(Role::Admin);

    $this->actingAs($user)
        ->withSession(['_token' => $csrf])
        ->post('/app/logout', ['_token' => $csrf])
        ->assertRedirect(route('app.login'));

    $this->assertGuest();
});

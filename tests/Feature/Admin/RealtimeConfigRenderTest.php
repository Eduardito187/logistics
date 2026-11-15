<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\User;

// Config de tiempo real del admin en RUNTIME: el panel emite <meta> con la clave pública de la app de
// sockets solo cuando BROADCAST_CONNECTION=pusher, y resources/js/echo.js la lee para conectar al mismo
// origen de la página. Así la misma imagen sirve en cualquier entorno, sin variables VITE_PUSHER_* de build.

beforeEach(function (): void {
    // El render hook incluye @vite(echo.js): sin manifest de build el render fallaría.
    $this->withoutVite();

    config([
        'broadcasting.connections.pusher.key' => 'public-key-for-test',
        'broadcasting.connections.pusher.secret' => 'never-leak-this-secret',
        'broadcasting.connections.pusher.options.cluster' => 'mt1',
    ]);
});

/**
 * Crea una cuenta con el rol dado y devuelve el HTML de una página del panel vista por ella.
 */
function adminPageHtmlAs(Role $role): string
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return (string) test()->actingAs($user)->get('/admin/drivers')->assertOk()->getContent();
}

it('emits the realtime config as meta tags when broadcasting is pusher', function (): void {
    config(['broadcasting.default' => 'pusher']);

    $html = adminPageHtmlAs(Role::Admin);

    expect($html)->toContain('<meta name="dismac-realtime-enabled" content="1">')
        ->toContain('<meta name="dismac-realtime-key" content="public-key-for-test">')
        ->toContain('<meta name="dismac-realtime-cluster" content="mt1">')
        // El token CSRF que usa /broadcasting/auth lo emite el layout del panel.
        ->toContain('name="csrf-token"');
});

it('never exposes the sockets secret in the page', function (): void {
    config(['broadcasting.default' => 'pusher']);

    expect(adminPageHtmlAs(Role::Admin))->not->toContain('never-leak-this-secret');
});

it('emits nothing when broadcasting is off', function (string $driver): void {
    config(['broadcasting.default' => $driver]);

    expect(adminPageHtmlAs(Role::Admin))->not->toContain('dismac-realtime')
        ->not->toContain('public-key-for-test');
})->with(['null', 'log']);

it('emits nothing when the public key is missing', function (): void {
    config(['broadcasting.default' => 'pusher', 'broadcasting.connections.pusher.key' => '']);

    expect(adminPageHtmlAs(Role::Admin))->not->toContain('dismac-realtime');
});

it('renders the config view standalone with only the public key', function (): void {
    config(['broadcasting.default' => 'pusher']);

    $html = view('filament.realtime-echo')->render();

    expect($html)->toContain('dismac-realtime-key')
        ->toContain('public-key-for-test')
        ->not->toContain('never-leak-this-secret');

    config(['broadcasting.default' => 'null']);

    expect(trim(view('filament.realtime-echo')->render()))->toBe('');
});

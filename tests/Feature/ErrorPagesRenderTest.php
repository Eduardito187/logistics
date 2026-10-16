<?php

declare(strict_types=1);

use App\Models\User;

/*
 * Verifica que TODAS las páginas de error HTTP renderizan correctamente, que no dependen de assets
 * (Vite/Vue) y que el CTA es consciente de sesión (@auth/@guest). Las páginas son críticas: deben
 * mostrarse incluso si el servidor falla, por eso se comprueba el render directo de la vista además
 * de la ruta real con el handler normal de Laravel.
 */

// Todos los códigos que deben existir en resources/views/errors.
$codes = [401, 402, 403, 404, 405, 408, 409, 419, 422, 429, 500, 502, 503, 504];

it('resuelve el 404 real vía el handler normal y muestra la página de error', function (): void {
    $this->get('/una-ruta-que-no-existe-jamas')
        ->assertStatus(404)
        ->assertSee('Página no encontrada')
        ->assertSee('Dismac');
});

it('renderiza la vista de cada código de error', function (int $code): void {
    $html = view("errors.{$code}")->render();

    expect($html)
        ->toContain((string) $code)        // código grande visible
        ->toContain('Dismac')              // marca
        ->not->toContain('@vite');          // no depende de assets compilados
})->with($codes);

it('no filtra detalles internos en las páginas 5xx', function (int $code): void {
    $html = view("errors.{$code}")->render();

    // Sin mensaje de excepción, stack ni rutas internas.
    expect($html)
        ->not->toContain('Exception')
        ->not->toContain('vendor/')
        ->not->toContain('#0 ');
})->with([500, 502, 503, 504]);

it('muestra el CTA de invitado cuando no hay sesión', function (): void {
    $this->get('/otra-ruta-inexistente')
        ->assertStatus(404)
        ->assertSee('Iniciar sesión')
        ->assertSee('/admin/login')
        ->assertDontSee('Ir al panel');
});

it('muestra el CTA del panel cuando el usuario está autenticado', function (): void {
    $this->actingAs(User::factory()->create());

    $this->get('/otra-ruta-inexistente-mas')
        ->assertStatus(404)
        ->assertSee('Ir al panel')
        ->assertSee('/admin')
        ->assertDontSee('Iniciar sesión');
});

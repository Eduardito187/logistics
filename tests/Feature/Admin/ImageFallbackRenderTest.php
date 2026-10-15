<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Logistics\Fleet\Database\Factories\DriverFactory;
use Tests\TestCase;

/**
 * Verifica que las imágenes del admin NUNCA quedan rotas ni dejan un hueco en blanco: cuando un chofer no
 * tiene foto (caso real de los seeds), la columna de foto debe renderizar el placeholder embebido
 * (data URI SVG), no un <img> con src vacío (que el navegador pinta como imagen rota).
 */
final class ImageFallbackRenderTest extends TestCase
{
    // Este test es una CLASE PHPUnit (no una closure de Pest), así que el `uses(RefreshDatabase)->in('Feature')`
    // de tests/Pest.php NO lo alcanza: hay que aplicar el trait explícitamente para que corran las migraciones
    // (si no, la tabla `users` no existe en una BD limpia de CI y el factory revienta).
    use RefreshDatabase;

    public function test_drivers_list_renders_placeholder_when_photo_is_missing(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(Role::Admin);

        // Un chofer sin foto: el disco no tiene archivo y photo_path es null.
        DriverFactory::new()->create([
            'name' => 'Chofer Sin Foto',
            'photo_path' => null,
        ]);

        $response = $this->actingAs($admin)->get('/admin/drivers');

        $response->assertOk();
        // El placeholder embebido aparece (data URI SVG), prueba de que hay fallback visible.
        $response->assertSee('data:image/svg+xml', escape: false);
        // Y no se emite un <img> con src vacío para la foto (lo que se vería como imagen rota).
        $this->assertStringNotContainsString('src=""', (string) $response->getContent());
    }
}

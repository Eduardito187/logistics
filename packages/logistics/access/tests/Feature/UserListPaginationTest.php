<?php

declare(strict_types=1);

use Logistics\Access\Application\Query\ListUsers\ListUsers;
use Logistics\Access\Application\ReadModel\UserView;
use Logistics\Access\Infrastructure\Persistence\Eloquent\Factory\UserFactory;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Shared\Application\ReadModel\Page;

/**
 * GET /api/v1/app/access/users — paginación estándar opt-in y filtros `role`/`search` (auditoría app #20).
 *
 * Se desactiva el middleware de puerta para ejercitar solo el contrato del controller y la cadena CQRS.
 * Corre contra PostgreSQL (logistics_test); skip sin él.
 */
beforeEach(function (): void {
    $this->withoutMiddleware();
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere el stack de Laravel booteado dentro de los containers.',
);

it('sin parámetros de página conserva la respuesta histórica (lista completa, sin meta)', function (): void {
    UserFactory::new()->count(3)->create();

    $this->getJson('/api/v1/app/access/users')
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonMissingPath('meta');
});

it('pagina con page/per_page y devuelve el meta estándar', function (): void {
    UserFactory::new()->count(5)->create();

    $this->getJson('/api/v1/app/access/users?page=1&per_page=2')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.count', 2)
        ->assertJsonPath('meta.page', 1)
        ->assertJsonPath('meta.per_page', 2)
        ->assertJsonPath('meta.total', 5)
        ->assertJsonPath('meta.total_pages', 3)
        ->assertJsonPath('meta.next_page', 2)
        ->assertJsonPath('meta.prev_page', null);
});

it('respeta los límites de página (última, fuera de rango y per_page acotado)', function (): void {
    UserFactory::new()->count(5)->create();

    // Última página: el resto, sin siguiente.
    $this->getJson('/api/v1/app/access/users?page=3&per_page=2')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.next_page', null)
        ->assertJsonPath('meta.prev_page', 2);

    // Fuera de rango: vacía, pero con el total real.
    $this->getJson('/api/v1/app/access/users?page=9&per_page=2')
        ->assertOk()
        ->assertJsonCount(0, 'data')
        ->assertJsonPath('meta.total', 5)
        ->assertJsonPath('meta.next_page', null);

    // per_page se acota a [1, 200] y page < 1 se lleva a 1.
    $this->getJson('/api/v1/app/access/users?page=0&per_page=999')
        ->assertOk()
        ->assertJsonPath('meta.page', 1)
        ->assertJsonPath('meta.per_page', 200)
        ->assertJsonCount(5, 'data');
});

it('sin page pero con per_page devuelve la página 1 con meta', function (): void {
    UserFactory::new()->count(3)->create();

    $this->getJson('/api/v1/app/access/users?per_page=50')
        ->assertOk()
        ->assertJsonPath('meta.page', 1)
        ->assertJsonPath('meta.per_page', 50)
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('meta.total_pages', 1);
});

it('pagina por cursor opaco hasta agotar la colección', function (): void {
    UserFactory::new()->count(3)->create();

    $first = $this->getJson('/api/v1/app/access/users?cursor=&per_page=2')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.has_more', true);

    $cursor = $first->json('meta.next_cursor');
    expect($cursor)->toBeString();

    $this->getJson('/api/v1/app/access/users?per_page=2&cursor='.urlencode((string) $cursor))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.has_more', false)
        ->assertJsonPath('meta.next_cursor', null);
});

it('filtra por rol y por búsqueda de nombre o email, combinable con la paginación', function (): void {
    UserFactory::new()->withRoles(['picker'])->create(['name' => 'Ana Picker', 'email' => 'ana.p@dismac.test']);
    UserFactory::new()->withRoles(['picker', 'driver'])->create(['name' => 'Beto Mixto', 'email' => 'beto@dismac.test']);
    UserFactory::new()->withRoles(['driver'])->create(['name' => 'Carla Chofer', 'email' => 'carla@dismac.test']);

    $this->getJson('/api/v1/app/access/users?role=picker')
        ->assertOk()
        ->assertJsonCount(2, 'data');

    $this->getJson('/api/v1/app/access/users?role=driver&page=1&per_page=1')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('meta.next_page', 2);

    // Búsqueda sin distinguir mayúsculas, por nombre…
    $this->getJson('/api/v1/app/access/users?search=CHOFER')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Carla Chofer');

    // …y por email.
    $this->getJson('/api/v1/app/access/users?search=beto@')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.email', 'beto@dismac.test');

    // Filtros combinados sin coincidencias: lista vacía.
    $this->getJson('/api/v1/app/access/users?role=picker&search=carla')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('ListUsers con limit devuelve un Page de UserView con el total del filtro', function (): void {
    UserFactory::new()->count(4)->create();
    UserFactory::new()->suspended()->create();

    $page = app(QueryBus::class)->ask(new ListUsers(status: 'active', limit: 3, offset: 0));

    expect($page)->toBeInstanceOf(Page::class)
        ->and($page->total)->toBe(4)
        ->and($page->items)->toHaveCount(3)
        ->and($page->items[0])->toBeInstanceOf(UserView::class);
});

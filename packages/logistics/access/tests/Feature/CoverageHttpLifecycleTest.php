<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Logistics\Access\Application\Command\RecordUserLogin\RecordUserLogin;
use Logistics\Access\Infrastructure\Persistence\Eloquent\Factory\IntegrationTokenFactory;
use Logistics\Access\Infrastructure\Persistence\Eloquent\Factory\RoleFactory;
use Logistics\Access\Infrastructure\Persistence\Eloquent\Factory\UserFactory;
use Logistics\Access\Presentation\Http\Controller\VerifyIntegrationTokenController;
use Logistics\Access\Presentation\Http\Middleware\AuthenticateIntegrationToken;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Cobertura de los controllers HTTP de LECTURA (GetUser/GetRole/GetIntegrationToken) y de CICLO DE VIDA
 * (suspend/reactivate/password/rotate/revoke/delete) del contexto Access, por sus rutas reales de la
 * puerta `app`. Se omite el middleware de puerta para ejercitar el controller + su FormRequest + el bus
 * CQRS (los handlers 0% cubiertos se ejercitan de paso). Corre contra PostgreSQL (logistics_test); skip sin él.
 */
beforeEach(function (): void {
    $this->withoutMiddleware();
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere el stack de Laravel booteado dentro de los containers.',
);

// ──────────────────────────── Controllers de lectura (GET + 404) ────────────────────────────

it('GET users/{id} devuelve el usuario y 404 cuando no existe', function (): void {
    $user = UserFactory::new()->create();

    $this->getJson("/api/v1/app/access/users/{$user->getKey()}")
        ->assertOk()
        ->assertJsonStructure(['data']);

    $this->getJson('/api/v1/app/access/users/'.Str::uuid7())->assertStatus(404);
});

it('GET roles/{id} devuelve el rol y 404 cuando no existe', function (): void {
    $role = RoleFactory::new()->create();

    $this->getJson("/api/v1/app/access/roles/{$role->getKey()}")
        ->assertOk()
        ->assertJsonStructure(['data']);

    $this->getJson('/api/v1/app/access/roles/'.Str::uuid7())->assertStatus(404);
});

it('GET tokens/{id} devuelve el token y 404 cuando no existe', function (): void {
    $token = IntegrationTokenFactory::new()->create();

    $this->getJson("/api/v1/app/access/tokens/{$token->getKey()}")
        ->assertOk()
        ->assertJsonStructure(['data']);

    $this->getJson('/api/v1/app/access/tokens/'.Str::uuid7())->assertStatus(404);
});

// ──────────────────────────── Ciclo de vida del usuario ────────────────────────────

it('suspende y reactiva a un usuario (202 cada paso)', function (): void {
    $user = UserFactory::new()->create();

    $this->postJson("/api/v1/app/access/users/{$user->getKey()}/suspend")
        ->assertStatus(202)
        ->assertJsonPath('data.accepted', true);

    $this->postJson("/api/v1/app/access/users/{$user->getKey()}/reactivate")
        ->assertStatus(202)
        ->assertJsonPath('data.accepted', true);
});

it('cambia la contraseña de un usuario (202) y valida la entrada (422)', function (): void {
    $user = UserFactory::new()->create();

    $this->postJson("/api/v1/app/access/users/{$user->getKey()}/password", [
        'password' => 'nuevaClave123',
    ])->assertStatus(202);

    // Contraseña demasiado corta: 422.
    $this->postJson("/api/v1/app/access/users/{$user->getKey()}/password", [
        'password' => 'x',
    ])->assertStatus(422);
});

// ──────────────────────────── Ciclo de vida del token de integración ────────────────────────────

it('rota el secreto de un token activo y lo revela una vez (201)', function (): void {
    $token = IntegrationTokenFactory::new()->create();

    $this->postJson("/api/v1/app/access/tokens/{$token->getKey()}/rotate")
        ->assertStatus(201)
        ->assertJsonStructure(['data' => ['id', 'plain_token']]);
});

it('revoca un token de integración (202)', function (): void {
    $token = IntegrationTokenFactory::new()->create();

    $this->postJson("/api/v1/app/access/tokens/{$token->getKey()}/revoke")
        ->assertStatus(202)
        ->assertJsonPath('data.accepted', true);
});

// ──────────────────────────── Borrado de rol ────────────────────────────

it('elimina un rol del catálogo (202)', function (): void {
    $role = RoleFactory::new()->create();

    $this->deleteJson("/api/v1/app/access/roles/{$role->getKey()}")
        ->assertStatus(202)
        ->assertJsonPath('data.accepted', true);
});

// ──────────────────────────── RecordUserLogin (handler por el bus) ────────────────────────────

it('registra el inicio de sesión de un usuario por el CommandBus', function (): void {
    $user = UserFactory::new()->create();

    app(CommandBus::class)->dispatch(new RecordUserLogin(userId: (string) $user->getKey()));

    // El handler no lanza y actualiza la marca de último acceso: el usuario sigue existiendo.
    expect($user->fresh())->not->toBeNull();
});

// ──────────────────────────── VerifyIntegrationTokenController (proyección de atributos) ────────────────────────────

it('proyecta el token autenticado desde los atributos de la request', function (): void {
    $request = Request::create('/api/v1/integrations/access/token/verify', 'GET');
    $request->attributes->set(AuthenticateIntegrationToken::TOKEN_ID_ATTRIBUTE, 'tok-123');
    $request->attributes->set(AuthenticateIntegrationToken::PERMISSIONS_ATTRIBUTE, ['shipments.read', 'fleet.read']);

    $response = (new VerifyIntegrationTokenController)($request);

    expect($response->getStatusCode())->toBe(200);
    /** @var array{data: array{token_id: string, permission_keys: list<string>}} $payload */
    $payload = $response->getData(true);
    expect($payload['data']['token_id'])->toBe('tok-123')
        ->and($payload['data']['permission_keys'])->toBe(['shipments.read', 'fleet.read']);
});

it('devuelve 401 cuando la request no trae un token autenticado', function (): void {
    $response = (new VerifyIntegrationTokenController)(Request::create('/x', 'GET'));

    expect($response->getStatusCode())->toBe(401);
});

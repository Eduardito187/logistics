<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Logistics\Access\Application\Query\ListAuditEntries\ListAuditEntries;
use Logistics\Access\Application\ReadModel\AuditEntryView;
use Logistics\Access\Application\Service\IntegrationTokenIssuer;
use Logistics\Access\Presentation\Filament\Support\AuditRecorder;
use Logistics\Access\Presentation\Http\Middleware\AuthenticateIntegrationToken;
use Logistics\Access\Presentation\Http\Middleware\EnsurePermission;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Cobertura de los middlewares HTTP del contexto Access invocados directamente (Request + next) y del
 * puente de auditoría AuditRecorder. Corre contra PostgreSQL (logistics_test); skip sin él.
 */
beforeEach(function (): void {
    $this->withoutMiddleware();
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere el stack de Laravel booteado dentro de los containers.',
);

/**
 * next de prueba: responde 200 si la cadena llega hasta él.
 */
function covNext(): Closure
{
    return static fn ($request): Response => new Response('ok', 200);
}

it('rechaza sin token de integración (401)', function (): void {
    $middleware = new AuthenticateIntegrationToken(app(QueryBus::class), app(CommandBus::class));

    $response = $middleware->handle(Request::create('/api/v1/integrations/access/ping'), covNext());

    expect($response->getStatusCode())->toBe(401);
});

it('rechaza un token de integración inválido (401)', function (): void {
    $middleware = new AuthenticateIntegrationToken(app(QueryBus::class), app(CommandBus::class));

    $request = Request::create('/api/v1/integrations/access/ping');
    $request->headers->set('Authorization', 'Bearer prefijo.secreto-invalido');

    $response = $middleware->handle($request, covNext());

    expect($response->getStatusCode())->toBe(401);
});

it('autentica un token válido, deja permisos en la request y continúa (200)', function (): void {
    $issuer = app(IntegrationTokenIssuer::class);
    $issued = $issuer->issue('ERP Cov', ['shipments.read', 'fleet.read'], null, null);

    $middleware = new AuthenticateIntegrationToken(app(QueryBus::class), app(CommandBus::class));

    $request = Request::create('/api/v1/integrations/access/ping');
    $request->headers->set('Authorization', 'Bearer '.$issued->plainToken);

    $response = $middleware->handle($request, covNext());

    expect($response->getStatusCode())->toBe(200)
        ->and($request->attributes->get(AuthenticateIntegrationToken::PERMISSIONS_ATTRIBUTE))
        ->toEqualCanonicalizing(['shipments.read', 'fleet.read'])
        ->and($request->attributes->get(AuthenticateIntegrationToken::TOKEN_ID_ATTRIBUTE))->toBe($issued->id);
});

it('exige un permiso: 401 sin principal, 403 sin alcance, 200 con permiso', function (): void {
    $middleware = new EnsurePermission;

    // Sin permisos resueltos en la request: no autenticado por este carril.
    $noPrincipal = $middleware->handle(
        Request::create('/'),
        covNext(),
        'shipments.read',
    );
    expect($noPrincipal->getStatusCode())->toBe(401);

    // Con permisos pero sin el requerido: prohibido.
    $forbidden = Request::create('/');
    $forbidden->attributes->set(AuthenticateIntegrationToken::PERMISSIONS_ATTRIBUTE, ['fleet.read']);
    expect($middleware->handle($forbidden, covNext(), 'shipments.read')->getStatusCode())->toBe(403);

    // Con el permiso requerido (o comodín): continúa.
    $granted = Request::create('/');
    $granted->attributes->set(AuthenticateIntegrationToken::PERMISSIONS_ATTRIBUTE, ['shipments.read']);
    expect($middleware->handle($granted, covNext(), 'shipments.read')->getStatusCode())->toBe(200);

    $wildcard = Request::create('/');
    $wildcard->attributes->set(AuthenticateIntegrationToken::PERMISSIONS_ATTRIBUTE, ['*']);
    expect($middleware->handle($wildcard, covNext(), 'anything.read')->getStatusCode())->toBe(200);
});

it('registra una entrada de auditoría con el autor autenticado', function (): void {
    $user = User::factory()->create(['name' => 'Auditor Cov']);
    $this->actingAs($user);

    AuditRecorder::record('user.suspended', 'access.user', 'user-cov-1', ['reason' => 'test']);

    /** @var list<AuditEntryView> $entries */
    $entries = app(QueryBus::class)->ask(new ListAuditEntries(limit: 50, action: 'user.suspended'));

    expect($entries)->not->toBeEmpty()
        ->and($entries[0])->toBeInstanceOf(AuditEntryView::class)
        ->and($entries[0]->actorLabel)->toBe('Auditor Cov');
});

it('atribuye al sistema cuando no hay sesión autenticada', function (): void {
    AuditRecorder::record('role.deleted', 'access.role', 'role-cov-1');

    /** @var list<AuditEntryView> $entries */
    $entries = app(QueryBus::class)->ask(new ListAuditEntries(limit: 50, action: 'role.deleted'));

    expect($entries)->not->toBeEmpty()
        ->and($entries[0]->actorLabel)->toBe('System');
});

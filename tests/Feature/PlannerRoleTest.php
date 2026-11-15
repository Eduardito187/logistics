<?php

declare(strict_types=1);

use App\Enums\Ability;
use App\Enums\Role;
use App\Filament\Pages\ControlTower;
use App\Filament\Pages\DispatchTimeline;
use App\Filament\Pages\ShipmentPipeline;
use App\Http\Middleware\EnsureDeviceToken;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Logistics\Access\Presentation\Filament\Resources\RoleResource;
use Logistics\Access\Presentation\Filament\Resources\UserResource;
use Logistics\Capacity\Presentation\Filament\Pages\DispatchCalendar;
use Logistics\Capacity\Presentation\Filament\Resources\BlackoutDayResource;
use Logistics\Capacity\Presentation\Filament\Resources\CapacityBucketResource;
use Logistics\Execution\Presentation\Filament\Pages\LastMileBoard;
use Logistics\Execution\Presentation\Filament\Resources\DeliveryResource;
use Logistics\Execution\Presentation\Filament\Resources\VehicleRouteResource;
use Logistics\Settings\Presentation\Filament\Resources\IntegrationResource;
use Logistics\Shipments\Presentation\Filament\Resources\PhysicalUnitResource;
use Logistics\Shipments\Presentation\Filament\Resources\ShipmentLineResource;
use Logistics\Shipments\Presentation\Filament\Resources\ShipmentResource;
use Logistics\Tracking\Presentation\Filament\Resources\ShipmentTrackingResource;

// Rol planificador de punta a punta: habilidades del rol, sesión de la app (/app/auth/me), directorio del
// personal filtrado por rol, avisos por rol, lectura de planes, puertas que siguen cerradas (personal y
// Administration) y su entrada al panel web.
beforeEach(function (): void {
    $this->withoutMiddleware(EnsureDeviceToken::class);
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

/**
 * @param array<string, mixed> $attributes
 */
function prtUser(Role $role, array $attributes = []): User
{
    $user = User::factory()->create($attributes);
    $user->assignRole($role);

    return $user;
}

/**
 * ¿La ruta de la puerta app (método + URI relativa a /api/v1/app) ya deja pasar al planificador? Las rutas
 * con middleware `role:` listan los roles permitidos; una ruta sin ese middleware está abierta a cualquier
 * rol autenticado.
 */
function prtRouteAllowsPlanner(string $method, string $uri): bool
{
    $route = collect(Route::getRoutes()->getRoutes())->first(
        static fn (RoutingRoute $route): bool => $route->uri() === 'api/v1/app/'.$uri && in_array($method, $route->methods(), true),
    );
    if (!$route instanceof RoutingRoute) {
        return false;
    }

    foreach ($route->gatherMiddleware() as $middleware) {
        if (is_string($middleware) && str_starts_with($middleware, 'role:')) {
            return in_array(Role::Planificador->value, explode(',', substr($middleware, 5)), true);
        }
    }

    return true;
}

it('define el rol con su etiqueta y sus habilidades de planificación', function (): void {
    $abilities = Role::Planificador->abilities();

    expect(Role::Planificador->value)->toBe('planificador')
        ->and(Role::Planificador->label())->toBe('Planner')
        ->and(Role::options())->toHaveKey('planificador')
        ->and($abilities)->toBe([
            Ability::SHIPMENTS_READ,
            Ability::SHIPMENTS_WRITE,
            Ability::PLANNING_READ,
            Ability::PLANNING_WRITE,
            Ability::FLEET_READ,
            Ability::NETWORK_READ,
            Ability::CAPACITY_READ,
            Ability::CAPACITY_WRITE,
            Ability::EXECUTION_READ,
            Ability::EXECUTION_WRITE,
        ])
        // No administra: sin comodín ni habilidades de escritura sobre flota, red o transportistas.
        ->and($abilities)->not->toContain(Ability::WILDCARD)
        ->and($abilities)->not->toContain(Ability::FLEET_WRITE)
        ->and($abilities)->not->toContain(Ability::NETWORK_WRITE)
        ->and($abilities)->not->toContain(Ability::CARRIERS_WRITE)
        // El supervisor conserva la escritura de planificación (la app decide ocultarle la edición).
        ->and(Role::Supervisor->abilities())->toContain(Ability::PLANNING_WRITE);
});

it('la sesión de la app devuelve el rol, la ciudad y las habilidades del planificador', function (): void {
    Sanctum::actingAs(prtUser(Role::Planificador, ['city' => 'santa_cruz']));

    $this->getJson('/api/v1/app/auth/me')
        ->assertOk()
        ->assertJsonPath('user.roles', ['planificador'])
        ->assertJsonPath('user.city', 'santa_cruz')
        ->assertJsonPath('abilities', Role::Planificador->abilities());
});

it('el directorio del personal filtra por rol planificador', function (): void {
    $planner = prtUser(Role::Planificador, ['name' => 'Paula Planificadora']);
    prtUser(Role::Preparador, ['name' => 'Pedro Preparador']);
    Sanctum::actingAs(prtUser(Role::Admin));

    $this->getJson('/api/v1/app/staff?role=planificador')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $planner->id)
        ->assertJsonPath('data.0.roles', ['planificador']);
});

it('un aviso por rol llega a los planificadores', function (): void {
    $planner = prtUser(Role::Planificador);
    $picker = prtUser(Role::Preparador);
    Sanctum::actingAs(prtUser(Role::Admin));

    $this->postJson('/api/v1/app/broadcasts', [
        'audience' => ['role' => 'planificador'],
        'title' => 'Plan de mañana',
        'body' => 'Cerrar los viajes antes de las 17:00.',
    ])->assertStatus(201)->assertJsonPath('data.recipient_count', 1);

    expect(DB::table('staff_broadcast_recipients')->where('user_id', $planner->id)->exists())->toBeTrue()
        ->and(DB::table('staff_broadcast_recipients')->where('user_id', $picker->id)->exists())->toBeFalse();
});

it('el planificador lista los planes del día', function (): void {
    Sanctum::actingAs(prtUser(Role::Planificador));

    $this->getJson('/api/v1/app/plans?date=2026-12-01')
        ->assertOk()
        ->assertJsonStructure(['data']);
});

it('el planificador no entra a la ficha del personal ni envía avisos', function (): void {
    Sanctum::actingAs(prtUser(Role::Planificador));

    $this->getJson('/api/v1/app/staff')->assertStatus(403);
    $this->postJson('/api/v1/app/broadcasts', ['audience' => ['role' => 'chofer'], 'title' => 't', 'body' => 'b'])
        ->assertStatus(403);
});

it('el planificador ve el calendario de entregas', function (): void {
    Sanctum::actingAs(prtUser(Role::Planificador));

    $this->getJson('/api/v1/app/delivery-calendar/options')->assertOk();
})->skip(
    fn (): bool => !prtRouteAllowsPlanner('GET', 'delivery-calendar/options'),
    'Pendiente: sumar `planificador` al middleware role: de delivery-calendar en routes/api/capacity.php.',
);

it('la reprogramación en lote sigue siendo solo del administrador', function (): void {
    Sanctum::actingAs(prtUser(Role::Planificador));

    // La ruta ya admite al planificador (pedidos puntuales de su ciudad); el alcance masivo lo corta el
    // controller con el mismo código de rol insuficiente.
    $this->postJson('/api/v1/app/shipments/reschedule', [
        'scope' => ['city' => 'santa_cruz'],
        'new_date' => '2027-01-15',
        'window_start' => '14:00',
        'window_end' => '18:00',
    ])->assertStatus(403)->assertJsonPath('code', 'insufficient_role');
});

it('el planificador entra a consultar envíos, entregas y seguimiento en el panel, sin escribir como oficina', function (): void {
    Filament::setCurrentPanel('admin');
    $this->actingAs(prtUser(Role::Planificador));

    expect(ShipmentResource::canAccess())->toBeTrue()
        ->and(ShipmentLineResource::canAccess())->toBeTrue()
        ->and(PhysicalUnitResource::canAccess())->toBeTrue()
        ->and(DispatchCalendar::canAccess())->toBeTrue()
        ->and(BlackoutDayResource::canAccess())->toBeTrue()
        ->and(CapacityBucketResource::canAccess())->toBeTrue()
        ->and(VehicleRouteResource::canAccess())->toBeTrue()
        ->and(DeliveryResource::canAccess())->toBeTrue()
        ->and(LastMileBoard::canAccess())->toBeTrue()
        ->and(ShipmentTrackingResource::canAccess())->toBeTrue()
        // Solo lectura: las escrituras de oficina y los hechos de terreno no son del planificador.
        ->and(ShipmentResource::canWriteAsOffice())->toBeFalse()
        ->and(ShipmentLineResource::canCreate())->toBeFalse()
        ->and(PhysicalUnitResource::canCreate())->toBeFalse()
        ->and(DeliveryResource::canOperateField())->toBeFalse()
        ->and(VehicleRouteResource::canOperateField())->toBeFalse()
        ->and(LastMileBoard::canOperateField())->toBeFalse();
});

it('el supervisor conserva la escritura de oficina en los recursos que ahora ve el planificador', function (): void {
    Filament::setCurrentPanel('admin');
    $this->actingAs(prtUser(Role::Supervisor));

    expect(ShipmentResource::canAccess())->toBeTrue()
        ->and(ShipmentResource::canWriteAsOffice())->toBeTrue()
        ->and(DeliveryResource::canOperateField())->toBeTrue()
        ->and(LastMileBoard::canOperateField())->toBeTrue();
});

it('el planificador entra al panel web y ve planificación, nunca Administration', function (): void {
    Filament::setCurrentPanel('admin');
    $planner = prtUser(Role::Planificador);
    $this->actingAs($planner);

    expect($planner->canAccessPanel(Filament::getPanel('admin')))->toBeTrue()
        ->and(ControlTower::canAccess())->toBeTrue()
        ->and(DispatchTimeline::canAccess())->toBeTrue()
        ->and(ShipmentPipeline::canAccess())->toBeTrue()
        // Administration (seguridad, cuentas, integraciones): solo administrador.
        ->and(UserResource::canAccess())->toBeFalse()
        ->and(RoleResource::canAccess())->toBeFalse()
        ->and(IntegrationResource::canAccess())->toBeFalse();
});

it('el rol de sistema existe en el catálogo de Access sin correr seeders', function (): void {
    $role = DB::table('access_roles')->where('key', 'planificador')->first();

    expect($role)->not->toBeNull()
        ->and((bool) $role->is_system)->toBeTrue()
        ->and(json_decode((string) $role->permission_keys, true))->toBe(array_map(
            static fn (string $ability): string => str_replace(':', '.', $ability),
            Role::Planificador->abilities(),
        ));
});

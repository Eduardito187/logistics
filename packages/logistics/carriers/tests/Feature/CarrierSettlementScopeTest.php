<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Http\Middleware\EnsureDeviceToken;
use App\Http\Middleware\IdempotentRequests;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Logistics\Carriers\Infrastructure\Persistence\Eloquent\Factory\CarrierFactory;
use Logistics\Carriers\Infrastructure\Persistence\Eloquent\Factory\SettlementFactory;
use Logistics\Carriers\Infrastructure\Persistence\Eloquent\Factory\TariffFactory;

/**
 * Alcance SERVER-SIDE del rol transportista sobre /app/carriers/* (EnsureCarrierScope).
 *
 * Antes la app elegía "quién soy" de una lista de todos los transportistas y el backend confiaba en el
 * carrier_id del cliente: un transportista podía leer, aprobar, objetar o pagar liquidaciones AJENAS.
 * Ahora la identidad sale de users.carrier_id; ajeno → 403 RFC 7807; sin vínculo → 403; aprobar y pagar
 * son solo de oficina. Se desactivan solo la guarda de dispositivo e idempotencia (auth:sanctum y el
 * alcance quedan activos). Contra PostgreSQL (logistics_test).
 */
beforeEach(function (): void {
    $this->withoutMiddleware([EnsureDeviceToken::class, IdempotentRequests::class]);
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

/**
 * Crea un usuario con el rol dado (y transportista vinculado opcional) y lo autentica por Sanctum.
 */
function carrierScopeActingAs(Role $role, ?string $carrierId = null): User
{
    $user = User::factory()->create();
    $user->assignRole($role);
    $user->forceFill(['carrier_id' => $carrierId])->save();
    test()->actingAs($user, 'sanctum');

    return $user;
}

it('el transportista solo lista SUS liquidaciones e ignora el carrier_id del cliente', function (): void {
    $mine = CarrierFactory::new()->create(['name' => 'Transportes Propios']);
    $other = CarrierFactory::new()->create(['name' => 'Transportes Ajenos']);
    $own = SettlementFactory::new()->forCarrier($mine->id)->create();
    SettlementFactory::new()->forCarrier($other->id)->create();

    carrierScopeActingAs(Role::Transportista, $mine->id);

    // Aunque pida las del otro transportista, recibe solo las suyas.
    $this->getJson("/api/v1/app/carriers/settlements?carrier_id={$other->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $own->id)
        ->assertJsonPath('data.0.carrier_id', $mine->id);

    $this->getJson('/api/v1/app/carriers/settlements')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.carrier_id', $mine->id);
});

it('el transportista ve el detalle de su liquidación pero no el de una ajena (403)', function (): void {
    $mine = CarrierFactory::new()->create();
    $other = CarrierFactory::new()->create();
    $own = SettlementFactory::new()->forCarrier($mine->id)->create();
    $foreign = SettlementFactory::new()->forCarrier($other->id)->create();

    carrierScopeActingAs(Role::Transportista, $mine->id);

    $this->getJson("/api/v1/app/carriers/settlements/{$own->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $own->id);

    $this->getJson("/api/v1/app/carriers/settlements/{$foreign->id}")
        ->assertForbidden()
        ->assertJsonPath('code', 'forbidden');
});

it('el transportista no puede objetar una liquidación ajena (403) y sí la suya', function (): void {
    $mine = CarrierFactory::new()->create();
    $other = CarrierFactory::new()->create();
    $own = SettlementFactory::new()->forCarrier($mine->id)->create();
    $foreign = SettlementFactory::new()->forCarrier($other->id)->create();

    carrierScopeActingAs(Role::Transportista, $mine->id);

    $this->postJson("/api/v1/app/carriers/settlements/{$foreign->id}/dispute", [
        'reason' => 'Intento sobre una liquidación ajena.',
    ])
        ->assertForbidden()
        ->assertJsonPath('code', 'forbidden');

    expect(DB::table('carrier_settlements')->where('id', $foreign->id)->value('status'))->toBe('submitted');

    $this->postJson("/api/v1/app/carriers/settlements/{$own->id}/dispute", [
        'reason' => 'El monto no coincide con lo pactado.',
    ])->assertStatus(202);

    expect(DB::table('carrier_settlements')->where('id', $own->id)->value('status'))->toBe('disputed');
});

it('el transportista no puede aprobar ninguna liquidación, ni propia ni ajena (solo oficina)', function (): void {
    $mine = CarrierFactory::new()->create();
    $other = CarrierFactory::new()->create();
    $own = SettlementFactory::new()->forCarrier($mine->id)->create();
    $foreign = SettlementFactory::new()->forCarrier($other->id)->create();

    carrierScopeActingAs(Role::Transportista, $mine->id);

    $this->postJson("/api/v1/app/carriers/settlements/{$foreign->id}/approve")
        ->assertForbidden()
        ->assertJsonPath('code', 'forbidden');
    $this->postJson("/api/v1/app/carriers/settlements/{$own->id}/approve")
        ->assertForbidden();

    expect(DB::table('carrier_settlements')->where('id', $foreign->id)->value('status'))->toBe('submitted')
        ->and(DB::table('carrier_settlements')->where('id', $own->id)->value('status'))->toBe('submitted');
});

it('el transportista no puede marcar pagada ninguna liquidación, ni propia ni ajena (solo oficina)', function (): void {
    $mine = CarrierFactory::new()->create();
    $other = CarrierFactory::new()->create();
    $own = SettlementFactory::new()->forCarrier($mine->id)->approved()->create();
    $foreign = SettlementFactory::new()->forCarrier($other->id)->approved()->create();

    carrierScopeActingAs(Role::Transportista, $mine->id);

    $this->postJson("/api/v1/app/carriers/settlements/{$foreign->id}/pay", ['payment_reference' => 'TRF-1'])
        ->assertForbidden()
        ->assertJsonPath('code', 'forbidden');
    $this->postJson("/api/v1/app/carriers/settlements/{$own->id}/pay", ['payment_reference' => 'TRF-2'])
        ->assertForbidden();

    expect(DB::table('carrier_settlements')->where('id', $foreign->id)->value('status'))->toBe('approved')
        ->and(DB::table('carrier_settlements')->where('id', $own->id)->value('status'))->toBe('approved');
});

it('un transportista sin transportista vinculado recibe 403 en todos los endpoints', function (): void {
    $carrier = CarrierFactory::new()->create();
    $settlement = SettlementFactory::new()->forCarrier($carrier->id)->create();

    carrierScopeActingAs(Role::Transportista);

    $this->getJson("/api/v1/app/carriers/settlements?carrier_id={$carrier->id}")->assertForbidden();
    $this->getJson("/api/v1/app/carriers/settlements/{$settlement->id}")->assertForbidden();
    $this->postJson("/api/v1/app/carriers/settlements/{$settlement->id}/dispute", ['reason' => 'x'])->assertForbidden();
    $this->postJson("/api/v1/app/carriers/settlements/{$settlement->id}/approve")->assertForbidden();
    $this->postJson("/api/v1/app/carriers/settlements/{$settlement->id}/pay", ['payment_reference' => 'TRF-3'])
        ->assertForbidden();
    $this->getJson('/api/v1/app/carriers')->assertForbidden();
});

it('un rol de campo que no es transportista (chofer) recibe 403 en liquidaciones', function (): void {
    $carrier = CarrierFactory::new()->create();
    $settlement = SettlementFactory::new()->forCarrier($carrier->id)->approved()->create();

    carrierScopeActingAs(Role::Chofer);

    $this->getJson('/api/v1/app/carriers/settlements')->assertForbidden();
    $this->postJson("/api/v1/app/carriers/settlements/{$settlement->id}/pay", ['payment_reference' => 'TRF-4'])
        ->assertForbidden();
});

it('el admin ve las liquidaciones de todos los transportistas y filtra por carrier_id', function (): void {
    $a = CarrierFactory::new()->create();
    $b = CarrierFactory::new()->create();
    SettlementFactory::new()->forCarrier($a->id)->create();
    SettlementFactory::new()->forCarrier($b->id)->create();

    carrierScopeActingAs(Role::Admin);

    $this->getJson('/api/v1/app/carriers/settlements')
        ->assertOk()
        ->assertJsonCount(2, 'data');

    $this->getJson("/api/v1/app/carriers/settlements?carrier_id={$b->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.carrier_id', $b->id);
});

it('el supervisor aprueba y marca pagada una liquidación de cualquier transportista', function (): void {
    $carrier = CarrierFactory::new()->create();
    $settlement = SettlementFactory::new()->forCarrier($carrier->id)->create();

    carrierScopeActingAs(Role::Supervisor);

    $this->postJson("/api/v1/app/carriers/settlements/{$settlement->id}/approve")->assertStatus(202);
    $this->postJson("/api/v1/app/carriers/settlements/{$settlement->id}/pay", ['payment_reference' => 'TRF-5'])
        ->assertStatus(202);

    expect(DB::table('carrier_settlements')->where('id', $settlement->id)->value('status'))->toBe('paid');
});

it('el transportista solo se ve a sí mismo en el listado y no lee tarifas ajenas ni comparativas', function (): void {
    $mine = CarrierFactory::new()->create(['name' => 'Yo Mismo SRL']);
    $other = CarrierFactory::new()->create(['name' => 'Competidor SRL']);
    TariffFactory::new()
        ->forCarrier($other->id)
        ->forCorridor('CD-LPZ', 'CD-SCZ', 'cd_to_cd')
        ->forVehicleClass('trailer')
        ->create();

    carrierScopeActingAs(Role::Transportista, $mine->id);

    $this->getJson('/api/v1/app/carriers')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $mine->id);

    $this->getJson("/api/v1/app/carriers/{$other->id}/tariff?corridor_from=CD-LPZ&corridor_to=CD-SCZ&corridor_type=cd_to_cd&vehicle_class=trailer")
        ->assertForbidden();
    $this->getJson('/api/v1/app/carriers/tariffs/cheapest?corridor_from=CD-LPZ&corridor_to=CD-SCZ&corridor_type=cd_to_cd')
        ->assertForbidden();
    $this->getJson('/api/v1/app/carriers/assignment?corridor_from=CD-LPZ&corridor_to=CD-SCZ&corridor_type=cd_to_cd&volume_m3=50')
        ->assertForbidden();
});

it('/auth/me devuelve el transportista del usuario transportista y null para oficina', function (): void {
    $carrier = CarrierFactory::new()->create(['name' => 'Cargo Andino']);

    carrierScopeActingAs(Role::Transportista, $carrier->id);

    $this->getJson('/api/v1/app/auth/me')
        ->assertOk()
        ->assertJsonPath('user.carrier.id', $carrier->id)
        ->assertJsonPath('user.carrier.name', 'Cargo Andino')
        ->assertJsonPath('user.carrier.tax_id', $carrier->tax_id);

    // Un admin con carrier_id cargado por error no expone transportista: solo aplica al rol transportista.
    carrierScopeActingAs(Role::Admin, $carrier->id);

    $this->getJson('/api/v1/app/auth/me')
        ->assertOk()
        ->assertJsonPath('user.carrier', null);
});

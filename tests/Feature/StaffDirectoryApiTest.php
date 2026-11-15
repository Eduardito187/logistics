<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Http\Middleware\EnsureDeviceToken;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

// Ficha del personal para el admin de la app: una sola vista para cualquier rol, paginada, con búsqueda,
// documentos (con subida), actividad, fotos y asignación a una tienda. Solo admin y supervisor.
beforeEach(function (): void {
    $this->withoutMiddleware(EnsureDeviceToken::class);
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

function sdaActingAs(Role $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);
    Sanctum::actingAs($user);

    return $user;
}

function sdaStore(string $name = 'Tienda Central'): string
{
    $id = (string) Str::uuid7();
    DB::table('network_nodes')->insert([
        'id' => $id,
        'code' => 'T-'.Str::upper(Str::random(6)),
        'name' => $name,
        'type' => 'store',
        'lat' => -17.78,
        'lng' => -63.18,
        'status' => 'active',
        'department' => 'Santa Cruz',
    ]);

    return $id;
}

it('lista el personal con búsqueda, rol y tienda, y pagina', function (): void {
    sdaActingAs(Role::Admin);
    $store = sdaStore();

    $clerk = User::factory()->create(['name' => 'Zulema Vendedora', 'email' => 'zulema@dismac.test', 'node_id' => $store]);
    $clerk->assignRole(Role::Tienda);
    $driver = User::factory()->create(['name' => 'Zacarías Chofer', 'email' => 'zacarias@dismac.test']);
    $driver->assignRole(Role::Chofer);

    $this->getJson('/api/v1/app/staff?q=zulema')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $clerk->id)
        ->assertJsonPath('data.0.roles', ['tienda'])
        ->assertJsonPath('data.0.node.name', 'Tienda Central')
        ->assertJsonPath('data.0.node.city', 'Santa Cruz');

    $this->getJson('/api/v1/app/staff?role=chofer&q=zacar')->assertOk()->assertJsonPath('data.0.id', $driver->id);
    $this->getJson('/api/v1/app/staff?node_id='.$store)->assertOk()->assertJsonPath('meta.total', 1);
    $this->getJson('/api/v1/app/staff?q=z&per_page=1&page=2')->assertOk()->assertJsonCount(1, 'data');
});

it('devuelve la ficha con roles, tienda y dispositivos, y 404 si no existe', function (): void {
    sdaActingAs(Role::Supervisor);
    $store = sdaStore();
    $person = User::factory()->create(['node_id' => $store]);
    $person->assignRole(Role::Preparador);
    DB::table('devices')->insert([
        'uuid' => (string) Str::uuid7(),
        'user_id' => $person->id,
        'fingerprint' => 'fp-'.Str::random(8),
        'platform' => 'android',
        'name' => 'Pixel 7',
        'last_seen_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->getJson('/api/v1/app/staff/'.$person->id)
        ->assertOk()
        ->assertJsonPath('data.roles', ['preparador'])
        ->assertJsonPath('data.node.id', $store)
        ->assertJsonPath('data.driver', null)
        ->assertJsonPath('data.current_vehicle', null)
        ->assertJsonPath('data.devices.0.name', 'Pixel 7');

    $this->getJson('/api/v1/app/staff/999999999')->assertStatus(404);
});

it('sube un documento a la ficha y lo lista con su enlace', function (): void {
    Storage::fake('id_document');
    sdaActingAs(Role::Admin);
    $person = User::factory()->create();

    $this->post('/api/v1/app/staff/'.$person->id.'/documents', [
        'document_type' => 'carnet_identidad',
        'reference' => '7788991 SC',
        'expires_on' => '2030-01-31',
        'file' => UploadedFile::fake()->image('carnet.jpg'),
    ], ['Accept' => 'application/json'])
        ->assertStatus(201)
        ->assertJsonPath('data.document_type', 'carnet_identidad')
        ->assertJsonPath('data.source', 'staff')
        ->assertJsonPath('data.expired', false);

    $list = $this->getJson('/api/v1/app/staff/'.$person->id.'/documents')->assertOk()->assertJsonPath('meta.total', 1);

    // Enlace temporal para ver el archivo (el disco simulado del test genera el suyo).
    expect($list->json('data.0.url'))->toBeString()->not->toBe('');

    // El documento con imagen aparece también en la pestaña de fotos.
    $this->getJson('/api/v1/app/staff/'.$person->id.'/photos')->assertOk()->assertJsonPath('data.0.kind', 'document');
});

it('asigna y quita la tienda de una persona', function (): void {
    sdaActingAs(Role::Admin);
    $store = sdaStore('Tienda Norte');
    $person = User::factory()->create();

    $this->putJson('/api/v1/app/staff/'.$person->id.'/node', ['node_id' => $store])
        ->assertOk()
        ->assertJsonPath('data.node.name', 'Tienda Norte');

    $this->putJson('/api/v1/app/staff/'.$person->id.'/node', ['node_id' => null])
        ->assertOk()
        ->assertJsonPath('data.node', null);

    $this->putJson('/api/v1/app/staff/'.$person->id.'/node', ['node_id' => (string) Str::uuid7()])->assertStatus(422);
});

it('devuelve la actividad paginada y filtrada por tipo', function (): void {
    sdaActingAs(Role::Admin);
    $person = User::factory()->create();
    DB::table('devices')->insert([
        'uuid' => (string) Str::uuid7(),
        'user_id' => $person->id,
        'fingerprint' => 'fp-'.Str::random(8),
        'platform' => 'android',
        'name' => 'Galaxy A54',
        'last_seen_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->getJson('/api/v1/app/staff/'.$person->id.'/activity')
        ->assertOk()
        ->assertJsonPath('data.0.type', 'device')
        ->assertJsonPath('data.0.detail', 'Galaxy A54');

    $this->getJson('/api/v1/app/staff/'.$person->id.'/activity?type=scan')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/app/staff/'.$person->id.'/activity?type=otra')->assertStatus(422);
});

it('un chofer no puede ver la ficha del personal', function (): void {
    sdaActingAs(Role::Chofer);

    $this->getJson('/api/v1/app/staff')->assertStatus(403);
});

it('guarda los datos personales y la foto de alguien que no es chofer', function (): void {
    Storage::fake('id_document');
    sdaActingAs(Role::Admin);
    $person = User::factory()->create();
    $person->assignRole(Role::Tienda);

    $this->putJson('/api/v1/app/staff/'.$person->id.'/profile', [
        'phone' => '70012345',
        'national_id' => '5566778 SC',
        'address' => 'Av. Banzer 4to anillo',
        'emergency_contact_name' => 'Marta Rojas',
    ])
        ->assertOk()
        ->assertJsonPath('data.phone', '70012345')
        ->assertJsonPath('data.national_id', '5566778 SC')
        ->assertJsonPath('data.personal.address', 'Av. Banzer 4to anillo')
        ->assertJsonPath('data.personal.emergency_contact_name', 'Marta Rojas');

    // La fecha de nacimiento acepta también una fecha-hora ISO y guarda solo el día.
    $this->putJson('/api/v1/app/staff/'.$person->id.'/profile', ['birth_date' => '1990-05-01T00:00:00.000Z'])
        ->assertOk()
        ->assertJsonPath('data.personal.birth_date', '1990-05-01');

    // Solo cambia lo que viene; un null borra ese dato.
    $this->putJson('/api/v1/app/staff/'.$person->id.'/profile', ['phone' => null])
        ->assertOk()
        ->assertJsonPath('data.phone', null)
        ->assertJsonPath('data.national_id', '5566778 SC');

    $photo = $this->post('/api/v1/app/staff/'.$person->id.'/photo', [
        'photo' => UploadedFile::fake()->image('perfil.jpg'),
    ], ['Accept' => 'application/json'])->assertStatus(201);

    expect($photo->json('data.photo_url'))->toBeString()->not->toBe('');

    // Aparece en la lista y como foto de perfil en la pestaña de fotos.
    $this->getJson('/api/v1/app/staff?q='.urlencode($person->email))->assertOk()->assertJsonPath('data.0.national_id', '5566778 SC');
    $this->getJson('/api/v1/app/staff/'.$person->id.'/photos')->assertOk()->assertJsonPath('data.0.kind', 'profile');
});

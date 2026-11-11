<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Http\Middleware\EnsureDeviceToken;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Logistics\Labels\Application\Command\RegisterPrinter\RegisterPrinter;
use Logistics\Labels\Application\Command\RemovePrinter\RemovePrinter;
use Logistics\Labels\Application\Command\UpdatePrinter\UpdatePrinter;
use Logistics\Labels\Domain\Exception\InvalidPrinterException;
use Logistics\Labels\Domain\ValueObject\PrinterEndpoint;
use Logistics\Labels\Infrastructure\Persistence\Eloquent\PrinterRecord;
use Logistics\Labels\Presentation\Filament\Resources\PrinterResource\Pages\CreatePrinter;
use Logistics\Labels\Presentation\Filament\Resources\PrinterResource\Pages\EditPrinter;
use Logistics\Labels\Presentation\Filament\Resources\PrinterResource\Pages\ListPrinters;
use Logistics\Network\Infrastructure\Persistence\Eloquent\NodeRecord;
use Logistics\Shared\Application\Command\CommandBus;

// Registro central de impresoras de red por sede + GET /api/v1/app/printers (pedido de la app, 2026-11-11).
// En sedes con WiFi y cable en redes separadas el teléfono no puede descubrir la impresora: se registra
// una vez en el admin y la app la ofrece. Alcance: usuario de un nodo ve las de su sede + compartidas;
// supervisor/admin ven todas las activas; cambiar la IP en el admin se refleja en la app.

beforeEach(function (): void {})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

/**
 * Registra una impresora por el CommandBus y devuelve su id.
 */
function registerPrinter(string $name, ?string $nodeId, string $host = '192.168.2.50', bool $active = true): string
{
    $id = Str::uuid7()->toString();
    app(CommandBus::class)->dispatch(new RegisterPrinter(
        printerId: $id,
        name: $name,
        model: 'ZT411',
        host: $host,
        port: 9100,
        language: 'zpl',
        nodeId: $nodeId,
        location: 'Dock 2',
        active: $active,
    ));

    return $id;
}

function printerUser(?string $nodeId, ?Role $role = null): User
{
    $user = User::factory()->create(['node_id' => $nodeId]);
    if ($role !== null) {
        $user->assignRole($role);
    }

    return $user;
}

/**
 * @return list<string>
 */
function printerNamesFor(User $user): array
{
    // Varias identidades en un mismo test: se resetea el guard para que cada consulta sea independiente.
    app('auth')->forgetGuards();
    Sanctum::actingAs($user);

    /** @var list<array{name: string}> $data */
    $data = test()->withoutMiddleware(EnsureDeviceToken::class)
        ->getJson('/api/v1/app/printers')
        ->assertOk()
        ->json('data');

    return array_map(static fn (array $row): string => $row['name'], $data);
}

describe('dominio', function (): void {
    it('valida host y puerto del endpoint', function (): void {
        expect(PrinterEndpoint::of(' 192.168.2.50 ')->host)->toBe('192.168.2.50')
            ->and(PrinterEndpoint::of('zebra-anden2.dismac.local', 6101)->port)->toBe(6101)
            ->and(PrinterEndpoint::of('fe80::1')->port)->toBe(PrinterEndpoint::DEFAULT_PORT);

        expect(fn () => PrinterEndpoint::of(''))->toThrow(InvalidPrinterException::class);
        expect(fn () => PrinterEndpoint::of('http://192.168.2.50'))->toThrow(InvalidPrinterException::class);
        expect(fn () => PrinterEndpoint::of('192.168.2.50', 0))->toThrow(InvalidPrinterException::class);
        expect(fn () => PrinterEndpoint::of('192.168.2.50', 70000))->toThrow(InvalidPrinterException::class);
    });
});

describe('API y comandos', function (): void {
    it('usuario de un nodo ve las de su sede + compartidas; supervisor/admin ven todas las activas', function (): void {
        $mine = NodeRecord::factory()->create(['code' => 'T-PRN-01', 'name' => 'Tienda Norte', 'type' => 'store']);
        $other = NodeRecord::factory()->create(['code' => 'T-PRN-02', 'name' => 'Tienda Sur', 'type' => 'store']);

        registerPrinter('Zebra Norte', (string) $mine->id);
        registerPrinter('Zebra Sur', (string) $other->id);
        registerPrinter('Ricoh Compartida', null, 'ricoh.dismac.local');
        registerPrinter('Zebra Norte Baja', (string) $mine->id, active: false);

        expect(printerNamesFor(printerUser((string) $mine->id, Role::Tienda)))
            ->toBe(['Ricoh Compartida', 'Zebra Norte']);
        expect(printerNamesFor(printerUser(null, Role::Chofer)))
            ->toBe(['Ricoh Compartida']);
        expect(printerNamesFor(printerUser((string) $mine->id, Role::Supervisor)))
            ->toBe(['Ricoh Compartida', 'Zebra Norte', 'Zebra Sur']);
        expect(printerNamesFor(printerUser(null, Role::Admin)))
            ->toBe(['Ricoh Compartida', 'Zebra Norte', 'Zebra Sur']);
    });

    it('devuelve el contrato completo con el nombre de la sede (sin segunda consulta)', function (): void {
        $node = NodeRecord::factory()->create(['code' => 'T-PRN-03', 'name' => 'Tienda Centro', 'type' => 'store']);
        $id = registerPrinter('Zebra Andén 2', (string) $node->id);

        Sanctum::actingAs(printerUser((string) $node->id, Role::Tienda));
        $this->withoutMiddleware(EnsureDeviceToken::class)
            ->getJson('/api/v1/app/printers')
            ->assertOk()
            ->assertExactJson(['data' => [[
                'id' => $id,
                'name' => 'Zebra Andén 2',
                'model' => 'ZT411',
                'host' => '192.168.2.50',
                'port' => 9100,
                'language' => 'zpl',
                'node_id' => (string) $node->id,
                'node_name' => 'Tienda Centro',
                'location' => 'Dock 2',
            ]]]);
    });

    it('cambiar la IP se refleja en la próxima consulta; borrar la quita', function (): void {
        $id = registerPrinter('Zebra Móvil', null);

        app(CommandBus::class)->dispatch(new UpdatePrinter(
            printerId: $id, name: 'Zebra Móvil', model: null, host: '10.168.50.20', port: 6101,
            language: 'zpl', nodeId: null, location: null, active: true,
        ));

        Sanctum::actingAs(printerUser(null, Role::Preparador));
        $this->withoutMiddleware(EnsureDeviceToken::class)
            ->getJson('/api/v1/app/printers')
            ->assertOk()
            ->assertJsonPath('data.0.host', '10.168.50.20')
            ->assertJsonPath('data.0.port', 6101)
            ->assertJsonPath('data.0.model', null);

        app(CommandBus::class)->dispatch(new RemovePrinter($id));
        expect(PrinterRecord::query()->find($id))->toBeNull();
    });

    it('rechaza un host inválido en el dominio', function (): void {
        expect(fn () => registerPrinter('Mala', null, 'no es un host'))
            ->toThrow(InvalidPrinterException::class);
    });

    it('exige token de la puerta app', function (): void {
        $this->getJson('/api/v1/app/printers')->assertUnauthorized();
    });
});

describe('admin Filament', function (): void {
    beforeEach(function (): void {
        Filament::setCurrentPanel('admin');
        $this->actingAs(printerUser(null, Role::Admin));
    });

    it('renderiza listado y alta', function (): void {
        registerPrinter('Zebra Listada', null);

        Livewire::test(ListPrinters::class)->assertOk()->assertSee('Zebra Listada')->assertSee('192.168.2.50:9100');
        Livewire::test(CreatePrinter::class)->assertOk();
    });

    it('da de alta y edita por el formulario (vía CommandBus)', function (): void {
        $node = NodeRecord::factory()->create(['code' => 'T-PRN-04', 'name' => 'Tienda Este', 'type' => 'store']);

        Livewire::test(CreatePrinter::class)
            ->set('data', [
                'name' => 'Zebra Formulario',
                'model' => 'ZD421',
                'host' => '192.168.2.77',
                'port' => 9100,
                'language' => 'zpl',
                'node_id' => (string) $node->id,
                'location' => 'Counter',
                'active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $record = PrinterRecord::query()->where('name', 'Zebra Formulario')->firstOrFail();
        expect($record->node_id)->toBe((string) $node->id)->and($record->active)->toBeTrue();

        Livewire::test(EditPrinter::class, ['record' => $record->getKey()])
            ->assertOk()
            ->set('data', [
                'name' => 'Zebra Formulario',
                'model' => 'ZD421',
                'host' => '192.168.2.78',
                'port' => 9100,
                'language' => 'zpl',
                'node_id' => (string) $node->id,
                'location' => 'Counter',
                'active' => false,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $record->refresh();
        expect($record->host)->toBe('192.168.2.78')->and($record->active)->toBeFalse();
    });

    it('valida el host en el formulario', function (): void {
        Livewire::test(CreatePrinter::class)
            ->set('data', ['name' => 'X', 'host' => 'http://bad', 'port' => 9100, 'language' => 'zpl', 'active' => true])
            ->call('create')
            ->assertHasFormErrors(['host']);
    });
});

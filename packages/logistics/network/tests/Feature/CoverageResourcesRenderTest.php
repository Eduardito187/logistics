<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Logistics\Network\Application\Command\DefineLeg\DefineLegCommand;
use Logistics\Network\Application\Command\RegisterNode\RegisterNodeCommand;
use Logistics\Network\Domain\Contract\LegRepository;
use Logistics\Network\Domain\Contract\NodeRepository;
use Logistics\Network\Domain\ValueObject\NodeCode;
use Logistics\Network\Presentation\Filament\Resources\LegResource\Pages\CreateLeg;
use Logistics\Network\Presentation\Filament\Resources\LegResource\Pages\ListLegs;
use Logistics\Network\Presentation\Filament\Resources\LegResource\Pages\ViewLeg;
use Logistics\Network\Presentation\Filament\Resources\NodeResource\Pages\CreateNode;
use Logistics\Network\Presentation\Filament\Resources\NodeResource\Pages\ListNodes;
use Logistics\Network\Presentation\Filament\Resources\NodeResource\Pages\ViewNode;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Cobertura de los recursos Filament del contexto network (Patrón A del brief): montar cada página
 * Livewire ejecuta las definiciones de table()/form()/schema()/infolist() de NodeResource y LegResource.
 * Como el contexto no tiene factories de Eloquent, los registros se crean por los comandos de alta
 * (RegisterNode/DefineLeg) y se resuelven sus ids por los repositorios. Contra PostgreSQL (logistics_test).
 */
beforeEach(function (): void {
    // Fuera de HTTP hay que fijar el panel actual a mano para que los recursos resuelvan su contexto.
    Filament::setCurrentPanel('admin');
    $admin = User::factory()->create();
    $admin->assignRole(Role::Admin);
    $this->actingAs($admin);
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');

/**
 * Registra un nodo por el comando de alta y devuelve su UUID (el comando no lo retorna).
 */
function coverageRegisterNode(string $code, string $type, float $lat, float $lng): string
{
    app(CommandBus::class)->dispatch(new RegisterNodeCommand($code, 'Nodo '.$code, $type, $lat, $lng, 'la_paz'));

    /** @var NodeRepository $nodes */
    $nodes = app(NodeRepository::class);
    $node = $nodes->findByCode(NodeCode::fromString($code));

    return $node?->id()->toString() ?? throw new RuntimeException("nodo no encontrado: {$code}");
}

it('renderiza el listado, creación y vista de nodos', function (): void {
    $id = coverageRegisterNode('CD-COB-'.random_int(1000, 9999), 'distribution_center', -16.51, -68.19);

    Livewire::test(ListNodes::class)->assertOk();
    Livewire::test(CreateNode::class)->assertOk();
    Livewire::test(ViewNode::class, ['record' => $id])->assertOk();
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');

it('renderiza el listado, creación y vista de tramos', function (): void {
    $suffix = random_int(1000, 9999);
    $from = coverageRegisterNode('CD-COB-'.$suffix, 'distribution_center', -16.51, -68.19);
    $to = coverageRegisterNode('T-COB-'.$suffix, 'store', -16.49, -68.13);

    app(CommandBus::class)->dispatch(new DefineLegCommand(
        fromNodeId: $from,
        toNodeId: $to,
        corridorType: 'cd_to_store',
    ));

    /** @var LegRepository $legs */
    $legs = app(LegRepository::class);
    $all = $legs->all();
    $legId = $all[array_key_last($all)]->id()->toString();

    Livewire::test(ListLegs::class)->assertOk();
    Livewire::test(CreateLeg::class)->assertOk();
    Livewire::test(ViewLeg::class, ['record' => $legId])->assertOk();
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');

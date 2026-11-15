<?php

declare(strict_types=1);

use Logistics\Network\Application\Contract\CityCatalogReader;
use Logistics\Network\Application\Query\ListCities\ListCitiesHandler;
use Logistics\Network\Application\Query\ListCities\ListCitiesQuery;
use Logistics\Network\Application\ReadModel\CityView;
use Logistics\Network\Domain\ValueObject\Department;

/**
 * Lector en memoria del catálogo de ciudades (los nodos llegan ordenados por código, como el real).
 *
 * @param list<array{id: string, code: string, name: string, type: string, department: string}> $nodes
 */
function citiesReaderWith(array $nodes): CityCatalogReader
{
    return new class($nodes) implements CityCatalogReader
    {
        /**
         * @param list<array{id: string, code: string, name: string, type: string, department: string}> $nodes
         */
        public function __construct(private array $nodes) {}

        public function activeNodes(): array
        {
            return $this->nodes;
        }
    };
}

it('devuelve siempre los nueve departamentos, con almacén central solo donde lo hay', function (): void {
    $handler = new ListCitiesHandler(citiesReaderWith([
        ['id' => 'n-1', 'code' => 'CD-LP-01', 'name' => 'CD La Paz', 'type' => 'distribution_center', 'department' => 'la_paz'],
        ['id' => 'n-2', 'code' => 'CD-LP-02', 'name' => 'CD El Alto', 'type' => 'central_warehouse', 'department' => 'la_paz'],
        ['id' => 'n-3', 'code' => 'T-LP-01', 'name' => 'Tienda La Paz', 'type' => 'store', 'department' => 'La Paz'],
        ['id' => 'n-4', 'code' => 'T-PT-01', 'name' => 'Tienda Potosí', 'type' => 'store', 'department' => 'Potosí'],
        ['id' => 'n-5', 'code' => 'X-01', 'name' => 'Sin departamento válido', 'type' => 'store', 'department' => 'atlantida'],
    ]));

    $cities = $handler->handle(new ListCitiesQuery);
    $byCode = [];
    foreach ($cities as $city) {
        $byCode[$city->code] = $city->toArray();
    }

    expect($cities)->toHaveCount(9)->each->toBeInstanceOf(CityView::class)
        ->and(array_keys($byCode))->toBe(Department::codes())
        // Varios almacenes en la ciudad: gana el primero por código.
        ->and($byCode['la_paz'])->toBe([
            'code' => 'la_paz',
            'name' => 'La Paz',
            'central_warehouse' => ['node_id' => 'n-1', 'code' => 'CD-LP-01', 'name' => 'CD La Paz'],
            'nodes_count' => 3,
        ])
        // Ciudad con nodos pero sin almacén central.
        ->and($byCode['potosi'])->toBe(['code' => 'potosi', 'name' => 'Potosí', 'central_warehouse' => null, 'nodes_count' => 1])
        // Ciudad sin nodos: igual aparece.
        ->and($byCode['pando'])->toBe(['code' => 'pando', 'name' => 'Pando', 'central_warehouse' => null, 'nodes_count' => 0]);
});

it('reconoce un departamento escrito como código o como nombre', function (): void {
    expect(Department::tryFromText('santa_cruz'))->toBe(Department::SantaCruz)
        ->and(Department::tryFromText('Santa Cruz'))->toBe(Department::SantaCruz)
        ->and(Department::tryFromText(' POTOSÍ '))->toBe(Department::Potosi)
        ->and(Department::tryFromText('la-paz'))->toBe(Department::LaPaz)
        ->and(Department::tryFromText('atlantida'))->toBeNull()
        ->and(Department::tryFromText(''))->toBeNull()
        ->and(Department::tryFromText(null))->toBeNull()
        ->and(Department::codes())->toHaveCount(9);
});

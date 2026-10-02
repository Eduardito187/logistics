<?php

declare(strict_types=1);

it('reports healthy dependencies on the v1 health endpoint', function (): void {
    $this->getJson('/api/v1/health')
        ->assertOk()
        ->assertExactJson([
            'status' => 'ok',
            'api_version' => 'v1',
            'checks' => ['database' => true, 'cache' => true],
        ]);
});

it('has PostGIS enabled in the test database', function (): void {
    $row = DB::selectOne('select postgis_version() as version');

    expect($row->version)->toBeString()->not->toBeEmpty();
});

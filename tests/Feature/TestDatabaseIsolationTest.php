<?php

declare(strict_types=1);

// Red de seguridad: si alguien quita force="true" de phpunit.xml, los tests correrían
// RefreshDatabase sobre la base de desarrollo del container. Este test lo detecta.
it('runs against the dedicated test database, never the development one', function (): void {
    expect(DB::connection()->getDatabaseName())->toBe('logistics_test');
});

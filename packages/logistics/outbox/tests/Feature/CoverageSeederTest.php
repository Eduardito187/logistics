<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Logistics\Outbox\Infrastructure\Persistence\Eloquent\Seeders\OutboxSeeder;

/**
 * Cobertura del seeder de demo del outbox (Patrón D del brief): siembra mensajes en los cuatro estados y
 * es idempotente (un segundo run no vuelve a sembrar, detectado por el error fijo del dead-letter).
 */
it('siembra mensajes de demo del outbox sin reventar', function (): void {
    $this->seed(OutboxSeeder::class);

    expect(DB::table('outbox_messages')->count())->toBeGreaterThan(0);
});

it('es idempotente: un segundo run no duplica los mensajes de demo', function (): void {
    $this->seed(OutboxSeeder::class);
    $after = DB::table('outbox_messages')->count();

    $this->seed(OutboxSeeder::class);

    expect(DB::table('outbox_messages')->count())->toBe($after);
});

<?php

declare(strict_types=1);

use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Tracking\Application\Contract\PublicLinkTtl;
use Logistics\Tracking\Infrastructure\Settings\SettingsPublicLinkTtl;

// Lee el TTL del enlace público desde Configuración por el QueryBus; tolerante al default (72 h).

/** QueryBus de prueba: devuelve un valor fijo (o lanza) y cuenta cuántas veces lo consultaron. */
function ttlBusReturning(mixed $value): QueryBus
{
    return new class($value) implements QueryBus
    {
        public int $calls = 0;

        public function __construct(private readonly mixed $value) {}

        public function ask(Query $query): mixed
        {
            $this->calls++;

            if ($this->value instanceof Throwable) {
                throw $this->value;
            }

            return $this->value;
        }
    };
}

it('devuelve las horas configuradas', function (): void {
    $ttl = new SettingsPublicLinkTtl(ttlBusReturning(['hours' => 120]));

    expect($ttl->hours())->toBe(120);
});

it('cae al default cuando el valor no es un arreglo', function (): void {
    $ttl = new SettingsPublicLinkTtl(ttlBusReturning('no-es-arreglo'));

    expect($ttl->hours())->toBe(PublicLinkTtl::DEFAULT_HOURS);
});

it('cae al default cuando falta la clave hours', function (): void {
    $ttl = new SettingsPublicLinkTtl(ttlBusReturning(['otra' => 1]));

    expect($ttl->hours())->toBe(PublicLinkTtl::DEFAULT_HOURS);
});

it('cae al default cuando la consulta falla', function (): void {
    $ttl = new SettingsPublicLinkTtl(ttlBusReturning(new RuntimeException('boom')));

    expect($ttl->hours())->toBe(PublicLinkTtl::DEFAULT_HOURS);
});

it('memoiza el valor dentro del request (consulta una sola vez)', function (): void {
    $bus = ttlBusReturning(['hours' => 48]);
    $ttl = new SettingsPublicLinkTtl($bus);

    $ttl->hours();
    $ttl->hours();

    expect($bus->calls)->toBe(1);
});

<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Redis;
use Mockery;

/**
 * Doble de Valkey para los tests de observabilidad (Ola 5).
 *
 * Reemplaza la fachada Redis por una conexión simulada: los pipelines se ejecutan contra este mismo
 * objeto, que anota cada comando en `$calls`; las lecturas (`HGETALL`, `INFO`) devuelven los datos
 * sembrados. Así los tests no dependen de un Valkey real ni escriben en el de desarrollo.
 */
final class FakeMetricsRedis
{
    /** @var list<array<int, mixed>> Comandos de escritura recibidos, en orden: [comando, ...argumentos]. */
    public array $calls = [];

    /** Cantidad de pipelines ejecutados (cada uno es una ida y vuelta). */
    public int $pipelines = 0;

    /**
     * Instala el doble en la fachada Redis para cualquier nombre de conexión.
     *
     * @param array<string, array<string, string>> $hashes         contenido de cada hash, por clave
     * @param list<int>                            $pipelineResult lo que devuelve cada pipeline
     * @param array<string, mixed>                 $info           respuesta de INFO
     */
    public static function install(array $hashes = [], array $pipelineResult = [], array $info = []): self
    {
        $fake = new self;

        $connection = Mockery::mock(Connection::class);

        $connection->shouldReceive('pipeline')->andReturnUsing(
            static function (callable $callback) use ($fake, $pipelineResult): array {
                $fake->pipelines++;
                $callback($fake);

                return $pipelineResult;
            },
        );

        $connection->shouldReceive('hgetall')->andReturnUsing(
            static fn (string $key): array => $hashes[$key] ?? [],
        );

        $connection->shouldReceive('hset')->andReturnUsing(
            static function (string $key, string $field, string $value) use ($fake): int {
                $fake->calls[] = ['hset', $key, $field, $value];

                return 1;
            },
        );

        $connection->shouldReceive('info')->andReturn($info);

        Redis::shouldReceive('connection')->andReturn($connection);

        return $fake;
    }

    /**
     * Hace de pipeline: anota el comando y sus argumentos.
     *
     * @param array<int, mixed> $arguments
     */
    public function __call(string $name, array $arguments): self
    {
        $this->calls[] = [strtolower($name), ...$arguments];

        return $this;
    }
}

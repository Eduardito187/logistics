<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Infrastructure\CrossContext;

use Illuminate\Database\ConnectionInterface;
use Throwable;

/**
 * Lectura CROSS-CONTEXT tolerante y segura dentro de una transacción.
 *
 * Las lecturas a tablas de otros contextos (fleet, execution, planning, shipments) son best-effort: si la
 * tabla no existe o la query falla, se devuelve el valor de respaldo. Pero en PostgreSQL una query fallida
 * ABORTA la transacción en curso (la de la ingesta, que abre el CommandBus) aunque se capture la excepción.
 * Por eso, si hay transacción abierta, la lectura corre dentro de un SAVEPOINT (`transaction()` anidado):
 * ante un error se revierte solo el savepoint y la transacción de la ingesta sigue sana.
 */
final class SafeRead
{
    private const string UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /**
     * @template T
     *
     * @param callable(): T $read
     * @param T             $fallback
     *
     * @return T
     */
    public static function run(ConnectionInterface $connection, callable $read, mixed $fallback): mixed
    {
        try {
            if ($connection->transactionLevel() > 0) {
                /** @var T $result */
                $result = $connection->transaction(static fn (): mixed => $read());

                return $result;
            }

            return $read();
        } catch (Throwable) {
            return $fallback;
        }
    }

    /**
     * ¿Es un UUID con forma válida? Evita mandar a una columna uuid de Postgres un valor que la haría fallar.
     */
    public static function isUuid(string $value): bool
    {
        return preg_match(self::UUID_PATTERN, $value) === 1;
    }

    /**
     * Filtra los ids con forma de UUID, sin repetir.
     *
     * @param list<string> $ids
     *
     * @return list<string>
     */
    public static function uuids(array $ids): array
    {
        return array_values(array_unique(array_filter($ids, static fn (string $id): bool => self::isUuid($id))));
    }
}

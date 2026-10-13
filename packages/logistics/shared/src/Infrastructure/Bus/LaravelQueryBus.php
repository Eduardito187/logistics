<?php

declare(strict_types=1);

namespace Logistics\Shared\Infrastructure\Bus;

use Illuminate\Contracts\Container\Container;
use Logistics\Shared\Application\HandlerRegistry;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * QueryBus sobre el contenedor: resuelve el handler de la consulta y devuelve su resultado.
 */
final readonly class LaravelQueryBus implements QueryBus
{
    public function __construct(
        private Container $container,
        private HandlerRegistry $registry,
    ) {}

    public function ask(Query $query): mixed
    {
        $handlerClass = $this->registry->handlerFor($query);
        /** @var QueryHandler<Query, mixed> $handler */
        $handler = $this->container->make($handlerClass);

        return $handler->handle($query);
    }
}

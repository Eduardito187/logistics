<?php

declare(strict_types=1);

namespace Logistics\Shared\Application;

use InvalidArgumentException;

/**
 * Mapa mensaje → clase del handler, compartido por el CommandBus y el QueryBus.
 *
 * Cada ServiceProvider de contexto registra sus pares (comando/consulta → handler) al arrancar.
 * El bus resuelve el handler desde el contenedor; así el handler recibe sus dependencias inyectadas.
 */
final class HandlerRegistry
{
    /** @var array<class-string, class-string> */
    private array $handlers = [];

    /**
     * @param class-string $messageClass
     * @param class-string $handlerClass
     */
    public function register(string $messageClass, string $handlerClass): void
    {
        $this->handlers[$messageClass] = $handlerClass;
    }

    /**
     * @return class-string
     */
    public function handlerFor(object $message): string
    {
        $class = $message::class;
        if (!isset($this->handlers[$class])) {
            throw new InvalidArgumentException("No hay handler registrado para {$class}.");
        }

        return $this->handlers[$class];
    }

    public function has(object $message): bool
    {
        return isset($this->handlers[$message::class]);
    }
}

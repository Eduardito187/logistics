<?php

declare(strict_types=1);

namespace Logistics\Execution\Domain\ValueObject;

use Logistics\Execution\Domain\Exception\ExecutionException;

/**
 * Contexto del dispositivo y usuario que originó un escaneo. Parte de la evidencia inmutable: quién
 * y con qué equipo registró el hecho en terreno.
 *
 * Referencia a usuarios/dispositivos por id string: no se acopla a otros contextos.
 */
final readonly class DeviceContext
{
    private function __construct(
        public string $deviceId,
        public string $userId,
    ) {}

    public static function of(string $deviceId, string $userId): self
    {
        $device = trim($deviceId);
        if ($device === '') {
            throw ExecutionException::because('el id del dispositivo no puede estar vacío');
        }

        $user = trim($userId);
        if ($user === '') {
            throw ExecutionException::because('el id del usuario no puede estar vacío');
        }

        return new self($device, $user);
    }

    public function equals(self $other): bool
    {
        return $this->deviceId === $other->deviceId && $this->userId === $other->userId;
    }
}

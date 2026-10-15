<?php

declare(strict_types=1);

namespace Logistics\Access\Domain\Model;

use DateTimeImmutable;
use Logistics\Access\Domain\Event\IntegrationTokenIssued;
use Logistics\Access\Domain\Event\IntegrationTokenPermissionsChanged;
use Logistics\Access\Domain\Event\IntegrationTokenRevoked;
use Logistics\Access\Domain\Event\IntegrationTokenRotated;
use Logistics\Access\Domain\Event\IntegrationTokenUsed;
use Logistics\Access\Domain\Exception\AccessException;
use Logistics\Access\Domain\ValueObject\IntegrationTokenId;
use Logistics\Access\Domain\ValueObject\PermissionKey;
use Logistics\Access\Domain\ValueObject\TokenStatus;
use Logistics\Shared\Domain\Aggregate\AggregateRoot;

/**
 * Token de integración: credencial de máquina a máquina con permisos seleccionables.
 *
 * El secreto en claro nunca vive en el agregado: solo su prefijo público (indexable) y el hash del
 * secreto. El valor en claro se revela una única vez al emitir o rotar (lo arma el IntegrationTokenIssuer
 * en Application). El agregado concede permisos por clave (respetando el comodín "*") y controla su
 * aptitud según estado y expiración.
 */
final class IntegrationToken extends AggregateRoot
{
    /**
     * @param list<string> $permissionKeys
     */
    private function __construct(
        private readonly IntegrationTokenId $id,
        private string $name,
        private string $tokenPrefix,
        private string $tokenHash,
        private array $permissionKeys,
        private TokenStatus $status,
        private ?DateTimeImmutable $expiresAt,
        private ?DateTimeImmutable $lastUsedAt,
        private readonly ?string $createdBy,
    ) {}

    /**
     * Emite un token nuevo. Arranca activo. El prefijo y el hash ya vienen calculados por Application
     * (el secreto en claro se generó y hasheó en los puertos; el dominio nunca lo ve).
     *
     * @param list<string> $permissionKeys
     */
    public static function issue(
        IntegrationTokenId $id,
        string $name,
        string $tokenPrefix,
        string $tokenHash,
        array $permissionKeys,
        ?DateTimeImmutable $expiresAt,
        ?string $createdBy,
    ): self {
        $name = trim($name);
        if ($name === '') {
            throw AccessException::because('El nombre del token de integración no puede estar vacío.');
        }

        $normalized = self::normalizePermissionKeys($permissionKeys);

        $token = new self(
            $id,
            $name,
            $tokenPrefix,
            $tokenHash,
            $normalized,
            TokenStatus::Active,
            $expiresAt,
            null,
            $createdBy,
        );
        $token->recordEvent(new IntegrationTokenIssued(
            $id->toString(),
            $name,
            $tokenPrefix,
            $normalized,
            $expiresAt,
        ));

        return $token;
    }

    /**
     * Reconstituye un token desde persistencia, sin grabar eventos (ya ocurrieron).
     *
     * @param list<string> $permissionKeys
     */
    public static function reconstitute(
        IntegrationTokenId $id,
        string $name,
        string $tokenPrefix,
        string $tokenHash,
        array $permissionKeys,
        TokenStatus $status,
        ?DateTimeImmutable $expiresAt,
        ?DateTimeImmutable $lastUsedAt,
        ?string $createdBy,
    ): self {
        return new self(
            $id,
            $name,
            $tokenPrefix,
            $tokenHash,
            array_values($permissionKeys),
            $status,
            $expiresAt,
            $lastUsedAt,
            $createdBy,
        );
    }

    /**
     * Revoca el token de forma permanente. Idempotente: si ya está revocado, no hace nada ni graba evento.
     */
    public function revoke(): void
    {
        if ($this->status === TokenStatus::Revoked) {
            return;
        }
        $this->status = TokenStatus::Revoked;
        $this->recordEvent(new IntegrationTokenRevoked($this->id->toString()));
    }

    /**
     * Reemplaza el conjunto completo de permisos del token (lo usa el admin).
     *
     * @param list<string> $keys
     */
    public function setPermissions(array $keys): void
    {
        $this->permissionKeys = self::normalizePermissionKeys($keys);
        $this->recordEvent(new IntegrationTokenPermissionsChanged(
            $this->id->toString(),
            $this->permissionKeys,
        ));
    }

    /**
     * Rota el secreto: adopta el prefijo y el hash nuevos. Solo se puede rotar un token activo.
     */
    public function rotate(string $newPrefix, string $newHash): void
    {
        if ($this->status === TokenStatus::Revoked) {
            throw AccessException::because('No se puede rotar un token de integración revocado.');
        }
        $this->tokenPrefix = $newPrefix;
        $this->tokenHash = $newHash;
        $this->recordEvent(new IntegrationTokenRotated(
            $this->id->toString(),
            $newPrefix,
        ));
    }

    /**
     * Registra el último uso del token. Sin lógica extra: solo deja constancia del momento.
     */
    public function recordUsage(DateTimeImmutable $at): void
    {
        $this->lastUsedAt = $at;
        $this->recordEvent(new IntegrationTokenUsed(
            $this->id->toString(),
            $at,
        ));
    }

    /**
     * Indica si el token puede autenticar ahora: activo y sin expirar (expiración nula = sin vencimiento).
     */
    public function isUsable(DateTimeImmutable $now): bool
    {
        if ($this->status !== TokenStatus::Active) {
            return false;
        }

        return $this->expiresAt === null || $this->expiresAt > $now;
    }

    /**
     * Indica si el token concede un permiso. El comodín "*" concede todo.
     */
    public function hasPermission(PermissionKey $permission): bool
    {
        foreach ($this->permissionKeys as $key) {
            $granted = PermissionKey::fromString($key);
            if ($granted->isWildcard() || $granted->equals($permission)) {
                return true;
            }
        }

        return false;
    }

    public function id(): IntegrationTokenId
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function tokenPrefix(): string
    {
        return $this->tokenPrefix;
    }

    /**
     * Hash del secreto, expuesto solo al mapper para persistirlo. Nunca se devuelve el secreto en claro.
     */
    public function tokenHash(): string
    {
        return $this->tokenHash;
    }

    /**
     * @return list<string>
     */
    public function permissionKeys(): array
    {
        return $this->permissionKeys;
    }

    public function status(): TokenStatus
    {
        return $this->status;
    }

    public function expiresAt(): ?DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function lastUsedAt(): ?DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function createdBy(): ?string
    {
        return $this->createdBy;
    }

    /**
     * Normaliza las claves con PermissionKey (valida formato) y deduplica preservando el orden.
     *
     * @param  list<string> $keys
     * @return list<string>
     */
    private static function normalizePermissionKeys(array $keys): array
    {
        $normalized = [];
        foreach ($keys as $key) {
            $value = PermissionKey::fromString($key)->value;
            if (!in_array($value, $normalized, true)) {
                $normalized[] = $value;
            }
        }

        return $normalized;
    }
}

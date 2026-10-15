<?php

declare(strict_types=1);

namespace Logistics\Access\Domain\Contract;

use Logistics\Access\Domain\Model\IntegrationToken;
use Logistics\Access\Domain\ValueObject\IntegrationTokenId;

/**
 * Puerto de persistencia de tokens de integración. La implementación vive en Infrastructure.
 */
interface IntegrationTokenRepository
{
    public function nextIdentity(): IntegrationTokenId;

    public function save(IntegrationToken $token): void;

    public function findById(IntegrationTokenId $id): ?IntegrationToken;

    /**
     * Ubica un token por su prefijo público (indexado y único). La autenticación lo usa para hallar la
     * fila candidata sin exponer el secreto; luego se verifica el hash.
     */
    public function findByPrefix(string $prefix): ?IntegrationToken;

    /**
     * @return list<IntegrationToken>
     */
    public function all(): array;
}

<?php

declare(strict_types=1);

namespace Logistics\Access\Infrastructure\Persistence\Eloquent\Mapper;

use DateTimeImmutable;
use DateTimeInterface;
use Logistics\Access\Domain\Model\IntegrationToken;
use Logistics\Access\Domain\ValueObject\IntegrationTokenId;
use Logistics\Access\Domain\ValueObject\TokenStatus;
use Logistics\Access\Infrastructure\Persistence\Eloquent\Model\IntegrationTokenRecord;

/**
 * Traduce entre el agregado IntegrationToken (dominio) y su fila Eloquent. Único punto que conoce ambos.
 */
final class IntegrationTokenMapper
{
    public function toDomain(IntegrationTokenRecord $record): IntegrationToken
    {
        $expiresAt = $record->expires_at;
        $lastUsedAt = $record->last_used_at;

        return IntegrationToken::reconstitute(
            IntegrationTokenId::fromString($record->id),
            $record->name,
            $record->token_prefix,
            $record->token_hash,
            array_values($record->permission_keys),
            TokenStatus::from($record->status),
            $expiresAt instanceof DateTimeInterface
                ? DateTimeImmutable::createFromInterface($expiresAt)
                : null,
            $lastUsedAt instanceof DateTimeInterface
                ? DateTimeImmutable::createFromInterface($lastUsedAt)
                : null,
            $record->created_by,
        );
    }

    /**
     * @return array{id: string, name: string, token_prefix: string, token_hash: string, permission_keys: list<string>, status: string, expires_at: DateTimeImmutable|null, last_used_at: DateTimeImmutable|null, created_by: string|null}
     */
    public function toRow(IntegrationToken $token): array
    {
        return [
            'id' => $token->id()->toString(),
            'name' => $token->name(),
            'token_prefix' => $token->tokenPrefix(),
            'token_hash' => $token->tokenHash(),
            'permission_keys' => array_values($token->permissionKeys()),
            'status' => $token->status()->value,
            'expires_at' => $token->expiresAt(),
            'last_used_at' => $token->lastUsedAt(),
            'created_by' => $token->createdBy(),
        ];
    }
}

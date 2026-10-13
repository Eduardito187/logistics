<?php

declare(strict_types=1);

namespace Logistics\Access\Application\Service;

use DateTimeImmutable;
use DateTimeInterface;
use Logistics\Access\Application\ReadModel\IssuedTokenView;
use Logistics\Access\Domain\Contract\IntegrationTokenRepository;
use Logistics\Access\Domain\Contract\SecretGenerator;
use Logistics\Access\Domain\Contract\TokenHasher;
use Logistics\Access\Domain\Exception\AccessException;
use Logistics\Access\Domain\Model\IntegrationToken;
use Logistics\Access\Domain\ValueObject\IntegrationTokenId;
use Logistics\Outbox\Application\RecordDomainEvents;

/**
 * Emite y rota tokens de integración.
 *
 * A diferencia de los comandos CQRS (void), estos métodos DEVUELVEN un valor por una razón de negocio
 * ineludible: el secreto en claro solo puede revelarse una única vez, al crearlo o rotarlo, y nunca se
 * puede recuperar después (solo se persiste su hash). Por eso la emisión/rotación no cabe en el bus de
 * comandos y vive como servicio de aplicación explícito.
 *
 * El secreto se genera por el puerto {@see SecretGenerator} (CSPRNG) y se hashea por {@see TokenHasher};
 * el dominio solo recibe el prefijo público y el hash. Los eventos salen al outbox igual que en un handler.
 */
final readonly class IntegrationTokenIssuer
{
    public function __construct(
        private IntegrationTokenRepository $tokens,
        private SecretGenerator $secretGenerator,
        private TokenHasher $tokenHasher,
        private RecordDomainEvents $recordEvents,
    ) {}

    /**
     * Emite un token nuevo y devuelve su valor en claro una sola vez.
     *
     * @param list<string> $permissionKeys
     */
    public function issue(
        string $name,
        array $permissionKeys,
        ?DateTimeImmutable $expiresAt,
        ?string $createdBy,
    ): IssuedTokenView {
        $plain = $this->secretGenerator->generate();

        $token = IntegrationToken::issue(
            $this->tokens->nextIdentity(),
            $name,
            $plain->prefix,
            $this->tokenHasher->hash($plain->secret),
            $permissionKeys,
            $expiresAt,
            $createdBy,
        );

        $this->tokens->save($token);

        $this->recordEvents->record($token->releaseEvents(), 'access.integration_token');

        return new IssuedTokenView(
            $token->id()->toString(),
            $token->name(),
            $token->tokenPrefix(),
            $plain->full(),
            $token->permissionKeys(),
            $token->expiresAt()?->format(DateTimeInterface::ATOM),
        );
    }

    /**
     * Rota el secreto de un token activo y devuelve el valor en claro nuevo una sola vez. Falla si no existe.
     */
    public function rotate(IntegrationTokenId $id): IssuedTokenView
    {
        $token = $this->tokens->findById($id);
        if ($token === null) {
            throw AccessException::because("No existe el token de integración {$id->toString()}.");
        }

        $plain = $this->secretGenerator->generate();

        $token->rotate($plain->prefix, $this->tokenHasher->hash($plain->secret));

        $this->tokens->save($token);

        $this->recordEvents->record($token->releaseEvents(), 'access.integration_token');

        return new IssuedTokenView(
            $token->id()->toString(),
            $token->name(),
            $token->tokenPrefix(),
            $plain->full(),
            $token->permissionKeys(),
            $token->expiresAt()?->format(DateTimeInterface::ATOM),
        );
    }
}

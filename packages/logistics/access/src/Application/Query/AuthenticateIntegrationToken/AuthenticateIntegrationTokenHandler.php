<?php

declare(strict_types=1);

namespace Logistics\Access\Application\Query\AuthenticateIntegrationToken;

use Logistics\Access\Application\ReadModel\TokenAuthResultView;
use Logistics\Access\Domain\Contract\IntegrationTokenRepository;
use Logistics\Access\Domain\Contract\TokenHasher;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;
use Logistics\Shared\Domain\Clock\Clock;

/**
 * Autentica un token de integración sin efectos secundarios (es una consulta).
 *
 * Parte el valor presentado en "<prefix>.<secret>", ubica la fila por el prefijo público, verifica el
 * secreto contra el hash almacenado (vía el puerto {@see TokenHasher}) y comprueba que el token sea usable
 * ahora (activo y no expirado, con la hora del {@see Clock}). Devuelve los permisos concedidos o null si
 * la autenticación falla por cualquier motivo: formato inválido, prefijo inexistente, secreto incorrecto
 * o token inusable. El registro del último uso lo hace aparte el comando RecordTokenUsage.
 *
 * @implements QueryHandler<AuthenticateIntegrationToken, TokenAuthResultView|null>
 */
final readonly class AuthenticateIntegrationTokenHandler implements QueryHandler
{
    public function __construct(
        private IntegrationTokenRepository $tokens,
        private TokenHasher $tokenHasher,
        private Clock $clock,
    ) {}

    public function handle(Query $query): ?TokenAuthResultView
    {
        assert($query instanceof AuthenticateIntegrationToken);

        // El token completo es "<prefix>.<secret>": partimos en el primer punto.
        $separatorAt = strpos($query->fullToken, '.');
        if ($separatorAt === false) {
            return null;
        }

        $prefix = substr($query->fullToken, 0, $separatorAt);
        $secret = substr($query->fullToken, $separatorAt + 1);
        if ($prefix === '' || $secret === '') {
            return null;
        }

        $token = $this->tokens->findByPrefix($prefix);
        if ($token === null) {
            return null;
        }

        if (!$this->tokenHasher->verify($secret, $token->tokenHash())) {
            return null;
        }

        if (!$token->isUsable($this->clock->now())) {
            return null;
        }

        return TokenAuthResultView::fromDomain($token);
    }
}

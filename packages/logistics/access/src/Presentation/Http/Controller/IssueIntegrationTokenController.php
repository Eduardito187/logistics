<?php

declare(strict_types=1);

namespace Logistics\Access\Presentation\Http\Controller;

use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Logistics\Access\Application\Service\IntegrationTokenIssuer;
use Logistics\Access\Presentation\Http\ApiController;
use Logistics\Access\Presentation\Http\Request\IssueIntegrationTokenRequest;

/**
 * Emite un token de integración nuevo vía el servicio IntegrationTokenIssuer y devuelve el secreto en
 * claro UNA sola vez (201). El secreto nunca se vuelve a mostrar: solo se persiste su hash.
 *
 * No pasa por el CommandBus a propósito: la emisión revela un valor (el secreto) que no cabe en un
 * comando void; por eso es un servicio de aplicación explícito.
 */
final class IssueIntegrationTokenController extends ApiController
{
    public function __construct(
        private readonly IntegrationTokenIssuer $issuer,
    ) {}

    public function __invoke(IssueIntegrationTokenRequest $request): JsonResponse
    {
        return $this->guard(function () use ($request): JsonResponse {
            /** @var list<string> $permissionKeys */
            $permissionKeys = array_values($request->input('permission_keys', []));

            $expiresAtRaw = $request->input('expires_at');
            $expiresAt = is_string($expiresAtRaw) && $expiresAtRaw !== ''
                ? new DateTimeImmutable($expiresAtRaw)
                : null;

            // El autor queda registrado para auditoría: el usuario Sanctum de la puerta app.
            $user = $request->user();
            $createdBy = $user !== null ? (string) $user->getAuthIdentifier() : null;

            $issued = $this->issuer->issue(
                name: $request->string('name')->toString(),
                permissionKeys: $permissionKeys,
                expiresAt: $expiresAt,
                createdBy: $createdBy,
            );

            // 201 con el token en claro: única oportunidad de leerlo.
            return $this->data($issued->toArray(), 201);
        });
    }
}

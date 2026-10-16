<?php

declare(strict_types=1);

namespace Logistics\SellerPortal\Presentation\Http\Controller;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Logistics\SellerPortal\Application\Contract\SellerContextResolver;
use Logistics\SellerPortal\Domain\Exception\SellerPortalException;
use Logistics\SellerPortal\Presentation\Http\TokenContext;

/**
 * Base de los controllers del portal del seller. Resuelve el `sellerId` del token autenticado y garantiza
 * que un seller nunca pueda leer datos de otro:
 *  - el seller sale del token (vía {@see SellerContextResolver}); si no se puede resolver → 403;
 *  - si la request trae `?seller_id=` o `X-Seller-Id`, DEBE coincidir con el del token → 403 si no.
 */
abstract class SellerPortalController
{
    public function __construct(
        protected readonly SellerContextResolver $resolver,
    ) {}

    /**
     * Devuelve el UUID del seller dueño del token, o corta la petición con 403 si no se puede autorizar.
     */
    protected function sellerId(Request $request): string
    {
        try {
            $sellerId = $this->resolver->resolve(TokenContext::permissions($request));
        } catch (SellerPortalException $exception) {
            throw $this->forbidden($exception->getMessage());
        }

        $requested = $this->requestedSellerId($request);
        if ($requested !== null && $requested !== $sellerId) {
            throw $this->forbidden('El seller_id solicitado no coincide con el del token.');
        }

        return $sellerId;
    }

    /**
     * @param list<array<string, mixed>> $data
     */
    protected function collection(string $sellerId, array $data): JsonResponse
    {
        return new JsonResponse([
            'data' => $data,
            'seller_id' => $sellerId,
        ]);
    }

    private function requestedSellerId(Request $request): ?string
    {
        $param = $request->query('seller_id');
        if (!is_string($param) || $param === '') {
            $param = $request->header('X-Seller-Id');
        }

        return is_string($param) && $param !== '' ? strtolower(trim($param)) : null;
    }

    private function forbidden(string $message): HttpResponseException
    {
        return new HttpResponseException(new JsonResponse([
            'error' => ['code' => 'forbidden', 'message' => $message],
        ], 403));
    }
}

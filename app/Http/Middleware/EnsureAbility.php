<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Problem\ErrorCode;
use App\Http\Problem\ProblemDetails;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exige que el token del usuario tenga TODAS las habilidades indicadas (permiso por
 * endpoint, P1-AUTH-03).
 *
 * Uso en rutas: `->middleware('ability:execution:write')`. El token de admin lleva el
 * comodín '*', por lo que `tokenCan()` concede cualquier habilidad.
 */
final class EnsureAbility
{
    public function handle(Request $request, Closure $next, string ...$abilities): Response
    {
        $user = $request->user();

        if ($user === null) {
            return ProblemDetails::fromCode(ErrorCode::Unauthenticated, 'Unauthenticated.')->toResponse($request);
        }

        foreach ($abilities as $ability) {
            if (!$user->tokenCan($ability)) {
                return ProblemDetails::fromCode(
                    ErrorCode::InsufficientScope,
                    'Your token lacks the required ability: '.$ability,
                )->toResponse($request);
            }
        }

        return $next($request);
    }
}

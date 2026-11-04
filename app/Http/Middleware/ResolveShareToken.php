<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Shares\ShareTokenService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Résolution du jeton public (M10 §3) : share utilisable → injecté dans
 * la requête ; sinon 404 uniforme (jamais de distinction). Headers
 * anti-cache et anti-indexation sur toutes les réponses publiques.
 */
class ResolveShareToken
{
    public function __construct(private readonly ShareTokenService $tokens) {}

    public function handle(Request $request, Closure $next): Response
    {
        $share = $this->tokens->resolve((string) $request->route('token'));

        // 404 uniforme AVEC headers — abort() court-circuiterait leur pose.
        if ($share === null) {
            $response = response('Not Found', 404);
        } else {
            $request->attributes->set('share', $share);
            $response = $next($request);
        }

        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}

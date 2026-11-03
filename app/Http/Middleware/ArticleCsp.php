<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * CSP des pages de lecture d'articles (conception M1.12 §10.3) :
 * contenu externe assaini + images distantes directes (http/https
 * sans liste d'hôtes), Alpine.js 3 exigeant 'unsafe-eval' pour ses
 * expressions (`new Function`) — jamais 'unsafe-inline' en script.
 */
final class ArticleCsp
{
    private const POLICY = "default-src 'self'; "
        ."script-src 'self' 'unsafe-eval'; "
        ."style-src 'self' 'unsafe-inline'; "
        ."img-src 'self' https: http:; "
        ."font-src 'self'; "
        ."object-src 'none'; "
        ."frame-ancestors 'self'; "
        ."base-uri 'self'; "
        ."form-action 'self'";

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('Content-Security-Policy', self::POLICY);

        return $response;
    }
}

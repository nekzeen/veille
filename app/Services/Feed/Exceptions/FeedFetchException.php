<?php

namespace App\Services\Feed\Exceptions;

/**
 * Base des erreurs de récupération HTTP d'un flux.
 *
 * Sous-types :
 * - {@see FeedUrlException} : URL refusée (schéma, port, identifiants,
 *   hôte malformé ou ambigu) ;
 * - {@see FeedDnsException} : DNS sans réponse ou adresse non
 *   globalement routable (rejet strict des réponses mixtes, P3) ;
 * - {@see FeedTlsException} : échec de vérification TLS ;
 * - {@see FeedTimeoutException} : dépassement connexion/global/low-speed ;
 * - {@see FeedNetworkException} : autre erreur de transport (connexion,
 *   lecture) ;
 * - {@see FeedHttpException} : code HTTP >= 400 ou code non géré ;
 * - {@see FeedTooLargeException} : dépassement du plafond de 5 Mio ;
 * - {@see FeedRedirectException} : redirection invalide, refusée ou
 *   chaîne excessive.
 */
class FeedFetchException extends \RuntimeException {}

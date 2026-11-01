<?php

namespace App\Services\Feed\Exceptions;

/**
 * Réponse HTTP non exploitable : code >= 400, ou code hors des cas
 * gérés (2xx, 304, 3xx manuels). Le code est conservé pour
 * l'observabilité et le futur backoff.
 */
final class FeedHttpException extends FeedFetchException
{
    public function __construct(
        public readonly int $statusCode,
        string $message = '',
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            $message !== '' ? $message : "Réponse HTTP {$statusCode}.",
            0,
            $previous,
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Feed\Detection;

/**
 * La détection serveur a échoué ou le format n'est pas exploitable :
 * l'ajout de la source est refusé avec un message destiné à
 * l'utilisateur (jamais de détail technique).
 */
final class SourceNotCollectableException extends \RuntimeException
{
    public function __construct(public readonly DetectionResult $detection)
    {
        parent::__construct(
            DetectionResult::FAILURE_MESSAGES[$detection->status]
                ?? 'Cette source ne peut pas être collectée.',
        );
    }
}

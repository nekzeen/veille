<?php

declare(strict_types=1);

namespace App\Services\Feed\Detection;

use App\Services\Feed\Data\ParsedFeed;

/**
 * Proposition de configuration issue de la détection (M8.3) — jamais
 * une validation. Aucun champ n'est persisté par le détecteur.
 *
 * - `status` : `detected` (format reconnu), `needs_review` (format
 *   identifié, intervention requise) ou un code d'échec
 *   (`invalid_url`, `refused`, `unreachable`, `blocked`,
 *   `unsupported_content_type`, `unrecognized`) ;
 * - `configState` : état proposé (`detected`|`needs_review`), `null`
 *   en échec — jamais `validated` ;
 * - `connectorAvailable` : le connecteur proposé est-il collectable
 *   dans la version actuelle (rss/atom uniquement en M8.3) ;
 * - `parsed` : flux déjà parsé, réutilisé par la prévisualisation
 *   (non exposé).
 */
final class DetectionResult
{
    public const FAILURE_MESSAGES = [
        'invalid_url' => 'Adresse invalide : seules les URL http(s) publiques sont acceptées.',
        'refused' => 'Adresse refusée : destination non autorisée, introuvable ou redirection interdite.',
        'unreachable' => 'Source inaccessible pour le moment.',
        'blocked' => 'Source protégée par un dispositif anti-robot : collecte impossible.',
        'unsupported_content_type' => 'Type de contenu non pris en charge.',
        'unrecognized' => 'Aucun format de publication exploitable détecté.',
        // M13.2c — message volontairement générique : ne révèle pas
        // l'existence d'une source privée appartenant à un autre utilisateur.
        'unavailable' => 'Cette URL ne peut pas être ajoutée.',
    ];

    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public readonly string $status,
        public readonly string $submittedUrl,
        public readonly ?string $fetchUrl = null,
        public readonly ?string $connector = null,
        public readonly ?string $confidence = null,
        public readonly ?string $configState = null,
        public readonly bool $connectorAvailable = false,
        public readonly array $details = [],
        public readonly ?ParsedFeed $parsed = null,
    ) {}

    public static function failure(string $status, string $submittedUrl, array $details = []): self
    {
        return new self(status: $status, submittedUrl: $submittedUrl, details: $details);
    }

    public function isSuccessful(): bool
    {
        return $this->configState !== null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'submitted_url' => $this->submittedUrl,
            'fetch_url' => $this->fetchUrl,
            'connector' => $this->connector,
            'connector_available' => $this->connectorAvailable,
            'confidence' => $this->confidence,
            'config_state' => $this->configState,
            'message' => self::FAILURE_MESSAGES[$this->status] ?? null,
            'details' => $this->details,
        ];
    }
}

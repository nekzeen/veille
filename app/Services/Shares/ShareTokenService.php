<?php

declare(strict_types=1);

namespace App\Services\Shares;

use App\Models\VeilleShare;

/**
 * Cycle de vie des jetons de partage (M10_Conception_Partage §3) :
 * 32 octets aléatoires → base64url ; seul le SHA-256 persiste.
 * Le clair n'existe qu'en retour d'émission/rotation — jamais
 * relisible, jamais loggé.
 */
final class ShareTokenService
{
    /** 43 caractères [A-Za-z0-9_-] — segment de path sûr sans escape. */
    public function generatePlainToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public function hash(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }

    /**
     * Premier jeton d'un nouveau partage. Retourne le clair unique.
     */
    public function issue(VeilleShare $share): string
    {
        $plain = $this->generatePlainToken();
        $share->token_hash = $this->hash($plain);
        $share->save();

        return $plain;
    }

    /**
     * Rotation : écrase `token_hash` (l'ancien lien meurt immédiatement,
     * un seul jeton valide) et consigne `rotated_at` pour l'audit —
     * distinction nette avec la révocation (`revoked_at`).
     */
    public function rotate(VeilleShare $share): string
    {
        $share->rotated_at = now('UTC');

        return $this->issue($share);
    }

    public function revoke(VeilleShare $share): void
    {
        $share->forceFill([
            'is_active' => false,
            'revoked_at' => now('UTC'),
        ])->save();
    }

    /**
     * Résolution d'un lien public : share utilisable ou null —
     * jamais de distinction entre inexistant/expiré/révoqué (404).
     */
    public function resolve(string $plainToken): ?VeilleShare
    {
        if (mb_strlen($plainToken) > 64) {
            return null; // garde anti-entrée pathologique
        }

        return VeilleShare::query()
            ->where('token_hash', $this->hash($plainToken))
            ->usable()
            ->first();
    }
}

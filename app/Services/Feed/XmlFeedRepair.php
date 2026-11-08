<?php

declare(strict_types=1);

namespace App\Services\Feed;

/**
 * M12.17 — réparation conservatrice d'un document XML de flux.
 *
 * Règles STRICTES : uniquement des corrections dont l'innocuité est
 * démontrable — jamais de reformatage, de suppression de balises ou
 * d'interprétation du contenu :
 *   1. Octets illégaux en UTF-8 → retirés (mb_convert_encoding).
 *   2. Caractères de contrôle interdits par XML 1.0 → retirés
 *      (intervalle exact défini par la spécification : tab 0x09,
 *      LF 0x0A, CR 0x0D, 0x20-0xD7FF, 0xE000-0xFFFD, 0x10000+).
 * Renvoie le document corrigé, ou null si rien à réparer.
 */
final class XmlFeedRepair
{
    public static function repair(string $xml): ?string
    {
        // 1. Séquences UTF-8 illégales — suppression silencieuse des
        // octets malformés, les caractères valides sont préservés.
        $clean = mb_convert_encoding($xml, 'UTF-8', 'UTF-8');

        // 2. Caractères de contrôle interdits en XML 1.0 (hors
        // tab/LF/CR) : 0x00-0x08, 0x0B, 0x0C, 0x0E-0x1F, 0x7F.
        $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $clean) ?? $clean;

        return $clean === $xml ? null : $clean;
    }
}

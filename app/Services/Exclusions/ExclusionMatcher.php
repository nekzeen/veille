<?php

declare(strict_types=1);

namespace App\Services\Exclusions;

use App\Models\UserExclusion;
use App\Services\Feed\ArticleLinkResolver;
use App\Services\Feed\ArticleUrlNormalizer;
use App\Services\KeywordMatcher;

/**
 * Moteur d'exclusion (M9_Conception_Exclusions §3, §9.3) : retourne les
 * `exclusion_id` des règles ACTIVES d'un utilisateur qui correspondent
 * à un article. Point de vérité unique — la collecte (M9.3) et le
 * rétroactif (M9.4) passeront par cette même méthode.
 *
 * URL : la comparaison porte sur l'adresse RÉELLE de l'article
 * (`ArticleLinkResolver::resolve` puis `ArticleUrlNormalizer`) — jamais
 * le domaine du flux RSS.
 */
class ExclusionMatcher
{
    public function __construct(private readonly ArticleUrlNormalizer $urls) {}

    /**
     * @param  string  $haystack  titre + résumé + contenu concaténés (bruts)
     * @return list<int> exclusion_id des règles actives correspondantes
     */
    public function justifications(int $userId, ?string $url, string $haystack): array
    {
        $rules = UserExclusion::query()
            ->where('user_id', $userId)->where('is_active', true)
            ->get(['id', 'kind', 'pattern']);

        $resolved = ArticleLinkResolver::resolve($url);
        $canonical = $resolved === null ? null : $this->urls->normalize($resolved);
        $parts = $canonical === null ? null : parse_url($canonical);
        $host = $parts === null || $parts === false ? null : strtolower((string) ($parts['host'] ?? ''));
        $path = $parts === null || $parts === false ? '/' : (string) ($parts['path'] ?? '/');
        $text = KeywordMatcher::normalize($haystack);

        $ids = [];
        foreach ($rules as $rule) {
            $match = $rule->kind === UserExclusion::KIND_KEYWORD
                ? $this->matchesText((string) $rule->pattern, $text)
                : $this->matchesUrl((string) $rule->pattern, $host, $path, $canonical);
            if ($match) {
                $ids[] = (int) $rule->id;
            }
        }

        return $ids;
    }

    /**
     * Expression recherchée dans le texte normalisé — bornes de mots
     * Unicode pour éviter « art » ⊂ « partie ».
     */
    private function matchesText(string $pattern, string $normalizedHaystack): bool
    {
        $needle = KeywordMatcher::normalize($pattern);
        if ($needle === '' || $normalizedHaystack === '') {
            return false;
        }

        return preg_match(
            '/(?<![\p{L}\p{N}])'.preg_quote($needle, '/').'(?![\p{L}\p{N}])/u',
            $normalizedHaystack,
        ) === 1;
    }

    private function matchesUrl(string $pattern, ?string $host, string $path, ?string $canonical): bool
    {
        if ($host === null || $host === '') {
            return false;
        }

        if (str_starts_with($pattern, '*.')) {
            // *.indeed.com → hôtes SULS-DOMAINES uniquement, pas l'apex.
            return str_ends_with($host, '.'.strtolower(substr($pattern, 2)));
        }
        if (preg_match('#^https?://#i', $pattern) === 1) {
            return $canonical !== null && $this->urls->normalize($pattern) === $canonical;
        }
        if (str_starts_with($pattern, '/')) {
            $base = rtrim($pattern, '/');

            return $path === $base || str_starts_with($path, $base.'/');
        }

        // Domaine entier : égalité exacte (indeed.com ≠ notindeed.com,
        // ≠ sous-domaines — ceux-ci demandent *.indeed.com).
        return $host === strtolower($pattern);
    }
}

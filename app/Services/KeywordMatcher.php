<?php

namespace App\Services;

use App\Models\Article;
use App\Models\ArticleVeille;
use App\Models\UserSource;
use App\Models\Veille;
use App\Models\VeilleKeyword;
use App\Services\Data\ArticleMatchResult;
use Illuminate\Database\QueryException;
use Normalizer;

/**
 * Matching à l'ingestion (DataModel §4.D) : pour un article donné,
 * enregistre dans `article_veille` une correspondance par veille
 * **active** contenant la source de l'article et appartenant à un
 * utilisateur abonné à cette source (`user_sources`).
 *
 * Texte analysé : `title` + `summary` uniquement (ni `content`, ni
 * URL, ni nom de source). Correspondance par sous-chaîne sur texte
 * normalisé côté PHP — identique sur SQLite et MariaDB, jamais
 * dépendante des collations SQL.
 *
 * Idempotent : la PK (veille_id, article_id) et la pré-lecture des
 * correspondances existantes garantissent qu'une réexécution ne
 * crée ni doublon, ni modification de `created_at`/`matched_keyword`
 * historiques. Aucune transaction globale : chaque insert est
 * atomique ; un conflit d'unicité concurrent est absorbé via
 * {@see SourceSubscriptionService::isUniqueViolation()}, toute autre
 * erreur SQL est relancée.
 */
final class KeywordMatcher
{
    /**
     * Ligatures latines non décomposables en NFD. Équivalence de
     * convenance (typographie courante), pas une équivalence
     * linguistique : hors de cette table, aucune translittération.
     *
     * @var array<string, string>
     */
    private const LIGATURES = [
        'œ' => 'oe',
        'æ' => 'ae',
        'ß' => 'ss',
        'ø' => 'o',
        'đ' => 'd',
        'ł' => 'l',
        'þ' => 'th',
        'ð' => 'd',
        'ı' => 'i',
        'ĳ' => 'ij',
    ];

    /**
     * Enregistre les correspondances de l'article ; retourne le
     * bilan. Sûr en réexécution et sous exécutions concurrentes.
     */
    public function matchArticle(Article $article): ArticleMatchResult
    {
        if (! $article->exists) {
            return new ArticleMatchResult(0, 0, 0, 0);
        }

        // Périmètre : propriétaires d'une souscription à la source.
        // Un user_id appelant n'est jamais utilisé — seules les
        // données persistées déterminent le périmètre.
        $subscriberIds = UserSource::where('source_id', $article->source_id)
            ->pluck('user_id')->all();

        if ($subscriberIds === []) {
            return new ArticleMatchResult(0, 0, 0, 0);
        }

        $veilles = Veille::query()
            ->whereIn('user_id', $subscriberIds)
            ->where('is_active', true)
            ->whereHas('sources', fn ($query) => $query->where('sources.id', $article->source_id))
            ->with(['keywords' => fn ($query) => $query->orderBy('created_at')->orderBy('id')])
            ->get();

        $haystack = self::normalize($article->title.' '.($article->summary ?? ''));

        // Correspondances déjà persistées : pré-lecture pour
        // l'idempotence, le catch d'unicité couvre la course.
        $alreadyMatched = ArticleVeille::where('article_id', $article->id)
            ->whereIn('veille_id', $veilles->modelKeys())
            ->pluck('veille_id')->flip();

        $matched = $created = $existing = 0;

        foreach ($veilles as $veille) {
            $term = self::firstMatchingTerm($veille->keywords, $haystack);

            if ($term === null) {
                continue;
            }

            $matched++;

            if ($alreadyMatched->has($veille->id)) {
                $existing++;

                continue;
            }

            try {
                ArticleVeille::create([
                    'veille_id' => $veille->id,
                    'article_id' => $article->id,
                    'user_id' => $veille->user_id,
                    'matched_keyword' => $term,
                ]);
                $created++;
            } catch (QueryException $e) {
                // Seul un vrai doublon concurrent est absorbé.
                if (! SourceSubscriptionService::isUniqueViolation($e)) {
                    throw $e;
                }
                $existing++;
            }
        }

        return new ArticleMatchResult($veilles->count(), $matched, $created, $existing);
    }

    /**
     * Terme déclencheur d'un article pour un ensemble ordonné de
     * mots-clés (created_at, puis id), null si aucun. Partagé avec
     * le rematching pour ne pas dupliquer la normalisation (§4.D).
     *
     * @param  iterable<VeilleKeyword>  $keywords
     */
    public function matchedTerm(Article $article, iterable $keywords): ?string
    {
        $haystack = self::normalize($article->title.' '.($article->summary ?? ''));

        return self::firstMatchingTerm($keywords, $haystack);
    }

    /**
     * Premier terme correspondant, dans l'ordre de création des
     * mots-clés (created_at, puis id — collection triée à
     * l'eager-load). Retourne le texte stocké du terme, pas la forme
     * normalisée : `matched_keyword` conserve l'original (§3.8).
     *
     * @param  iterable<VeilleKeyword>  $keywords
     */
    private static function firstMatchingTerm(iterable $keywords, string $haystack): ?string
    {
        foreach ($keywords as $keyword) {
            $needle = trim(self::normalize($keyword->term));

            // Un terme vide après normalisation ne doit jamais
            // correspondre (str_contains('', x) serait toujours vrai).
            if ($needle !== '' && str_contains($haystack, $needle)) {
                return $keyword->term;
            }
        }

        return null;
    }

    /**
     * Forme de comparaison Unicode : entités HTML décodées, balises
     * remplacées par un espace (évite la fusion de mots aux
     * frontières), casse repliée (mb), décomposition NFD puis retrait
     * des marques diacritiques (\p{Mn}), ligatures latin courantes.
     *
     * Limites assumées : pas d'équivalence linguistique (homographes
     * translittérés non couverts), les entrées non UTF-8 valides sont
     * conservées telles quelles après repli de casse.
     */
    private static function normalize(?string $text): string
    {
        if ($text === null) {
            return '';
        }

        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/<[^>]+>/', ' ', $text) ?? '';
        $text = mb_strtolower($text, 'UTF-8');

        $nfd = Normalizer::normalize($text, Normalizer::FORM_D);
        if ($nfd !== false) {
            $text = $nfd;
        }

        $text = preg_replace('/\p{Mn}+/u', '', $text) ?? $text;
        $text = strtr($text, self::LIGATURES);

        // Suites de blancs Unicode (espaces, tabulations, retours,
        // insécables, artefacts de balises) → espace unique : un
        // mot-clé multi-mots reste comparable au texte normalisé.
        return preg_replace('/\p{White_Space}+/u', ' ', $text) ?? $text;
    }
}

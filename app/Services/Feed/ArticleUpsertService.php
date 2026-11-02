<?php

namespace App\Services\Feed;

use App\Models\Article;
use App\Models\Source;
use App\Services\Feed\Data\ArticleUpsertResult;
use App\Services\Feed\Data\FeedItemData;
use App\Services\SourceSubscriptionService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Déduplication et persistance d'un lot d'articles d'une source
 * (DataModel §4.B, décisions P8–P9).
 *
 * Chaîne par item : `dedup_key` = sha256 du meilleur identifiant
 * (guid exploitable → URL normalisée B.2 → repli
 * `title|published_at|summary[:120]`), `url_hash` = sha256 de l'URL
 * normalisée (toujours calculée — secours de rattrapage),
 * `content_hash` = sha256 des 4 champs mutables joints par \x1f (P8).
 *
 * Arbre : clé connue → update si contenu ou URL a bougé ; clé inconnue
 * + url_hash unique → GUID changé, update de la ligne ; url_hash
 * ambigu ou absent → INSERT, jamais de fusion.
 *
 * Transaction atomique par lot (P9) : un item invalide est ignoré +
 * compté ; une erreur systémique fait échouer le lot entier
 * (rollback). Aucune écriture `article_user`/`article_veille` ici.
 */
final class ArticleUpsertService
{
    public function __construct(
        private readonly ArticleUrlNormalizer $normalizer,
    ) {}

    /**
     * Persiste un lot d'articles parsés pour une source, en une
     * transaction. Une erreur systémique propage l'exception après
     * rollback complet.
     *
     * @param  list<FeedItemData>  $items
     */
    public function upsertBatch(Source $source, array $items): ArticleUpsertResult
    {
        return DB::transaction(function () use ($source, $items): ArticleUpsertResult {
            /** @var array{created:int, updated:int, unchanged:int, skipped:int, collisions:int, article_ids:list<int>} $stats */
            $stats = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0, 'collisions' => 0, 'article_ids' => []];

            foreach ($items as $item) {
                if (! $this->isStorable($item)) {
                    $stats['skipped']++;

                    continue;
                }

                $this->upsertItem($source, $item, $stats);
            }

            return new ArticleUpsertResult(
                received: count($items),
                created: $stats['created'],
                updated: $stats['updated'],
                unchanged: $stats['unchanged'],
                skipped: $stats['skipped'],
                collisions: $stats['collisions'],
                articleIds: $stats['article_ids'],
            );
        });
    }

    /**
     * Item persistable : colonnes NOT NULL du schéma satisfaites —
     * URL et titre exploitables. Tout le reste est ignoré + compté,
     * jamais d'échec du lot (P9).
     */
    private function isStorable(FeedItemData $item): bool
    {
        if ($item->url === null || trim($item->url) === '' || strlen(trim($item->url)) > 2048) {
            return false;
        }

        return $item->title !== null && trim($item->title) !== '';
    }

    /** @param  array{created:int, updated:int, unchanged:int, skipped:int, collisions:int, article_ids:list<int>}  $stats */
    private function upsertItem(Source $source, FeedItemData $item, array &$stats): void
    {
        $normalizedUrl = $this->normalizer->normalize($item->url);
        // url_hash toujours calculée ; si l'URL n'est pas normalisable
        // (forme invalide), le hachage porte sur la forme brute —
        // déterministe, l'item reste retrouvable.
        $urlHash = hash('sha256', $normalizedUrl ?? trim($item->url ?? ''));
        $dedupKey = hash('sha256', $this->dedupSource($item, $normalizedUrl));
        $contentHash = self::contentHash($item);

        // Clé connue : doublon exact ou mise à jour en place.
        $row = Article::where('source_id', $source->id)->where('dedup_key', $dedupKey)->first();
        if ($row !== null) {
            $this->refreshOrSkip($row, $item, $normalizedUrl, $urlHash, $dedupKey, $contentHash, $stats);

            return;
        }

        // Clé inconnue → secours url_hash (§4.B) :
        // 1 ligne = même article au GUID changé ; 0 ou ≥2 = insertion,
        // jamais de fusion sur correspondance ambiguë.
        $matches = Article::where('source_id', $source->id)->where('url_hash', $urlHash)->get();

        if ($matches->count() === 1) {
            $row = $matches->first();

            // Collision : la nouvelle clé appartient déjà à une AUTRE
            // ligne — possible en course d'insertion. La ligne la plus
            // ancienne conserve sa clé, l'entrante la sienne : pas
            // d'écrasement, anomalie journalisée, insertion relue via
            // la relecture UNIQUE.
            if (Article::where('source_id', $source->id)->where('dedup_key', $dedupKey)
                ->where('id', '!=', $row->id)->exists()) {
                $this->logAnomaly($source, 'dedup_key déjà présente sur une autre ligne au url_hash partagé');
                $stats['collisions']++;
                $this->insertArticle($source, $item, $normalizedUrl, $urlHash, $dedupKey, $contentHash, $stats);

                return;
            }

            // GUID changé : cette ligne est l'article — mise à jour
            // complète guid/dedup_key/url + champs mutables (§4.B).
            $row->fill($this->mutableAttributes($item, $normalizedUrl, $urlHash, $contentHash)
                + ['guid' => self::truncate($item->guid, 512), 'dedup_key' => $dedupKey]);
            $row->save();
            $stats['updated']++;
            $stats['article_ids'][] = $row->id;

            return;
        }

        if ($matches->count() > 1) {
            // Ambiguïté : plusieurs lignes partagent url_hash — aucune
            // fusion, l'item est inséré comme ligne distincte (§4.B).
            $this->logAnomaly($source, 'url_hash partagé par '.$matches->count().' lignes — insertion sans fusion');
            $stats['collisions']++;
        }

        $this->insertArticle($source, $item, $normalizedUrl, $urlHash, $dedupKey, $contentHash, $stats);
    }

    /**
     * Chemin « clé connue » : update si le contenu (content_hash) ou
     * l'URL a changé — couvre le changement d'URL à clé issue du GUID
     * (§4.B) — ; rien sinon. `collected_at` et `article_user/*`
     * préservés.
     *
     * @param  array{created:int, updated:int, unchanged:int, skipped:int, collisions:int, article_ids:list<int>}  $stats
     */
    private function refreshOrSkip(
        Article $row,
        FeedItemData $item,
        ?string $normalizedUrl,
        string $urlHash,
        string $dedupKey,
        string $contentHash,
        array &$stats,
    ): void {
        if ($row->content_hash === $contentHash && $row->url_hash === $urlHash) {
            $stats['unchanged']++;
            $stats['article_ids'][] = $row->id;

            return;
        }

        $row->fill($this->mutableAttributes($item, $normalizedUrl, $urlHash, $contentHash));
        $row->save();
        $stats['updated']++;
        $stats['article_ids'][] = $row->id;
    }

    /** @param  array{created:int, updated:int, unchanged:int, skipped:int, collisions:int, article_ids:list<int>}  $stats */
    private function insertArticle(
        Source $source,
        FeedItemData $item,
        ?string $normalizedUrl,
        string $urlHash,
        string $dedupKey,
        string $contentHash,
        array &$stats,
    ): void {
        try {
            $article = Article::create([
                'source_id' => $source->id,
                ...$this->attributesFor($item, $normalizedUrl, $urlHash, $dedupKey, $contentHash),
                // collected_at : instantané UTC au premier insert,
                // jamais modifié ensuite (§7 archi).
                'collected_at' => now('UTC'),
            ]);
            $stats['created']++;
            $stats['article_ids'][] = $article->id;
        } catch (QueryException $e) {
            // Violation UNIQUE = course d'insertion réelle : relire par
            // la clé et rejouer le chemin « clé connue ». Toute autre
            // erreur SQL est systémique → propagée, rollback du lot.
            if (! SourceSubscriptionService::isUniqueViolation($e)) {
                throw $e;
            }

            $row = Article::where('source_id', $source->id)->where('dedup_key', $dedupKey)->first();
            if ($row === null) {
                throw $e;
            }

            $this->refreshOrSkip($row, $item, $normalizedUrl, $urlHash, $dedupKey, $contentHash, $stats);
        }
    }

    /**
     * Attributs persistables à l'insertion. `url` = forme ORIGINALE du
     * flux (lien canonique) ; la comparaison passe par `url_hash`.
     * Champs dimensionnés au schéma.
     *
     * @return array<string, mixed>
     */
    private function attributesFor(
        FeedItemData $item,
        ?string $normalizedUrl,
        string $urlHash,
        string $dedupKey,
        string $contentHash,
    ): array {
        return $this->mutableAttributes($item, $normalizedUrl, $urlHash, $contentHash) + [
            'guid' => self::truncate($item->guid, 512),
            'dedup_key' => $dedupKey,
            'published_at' => $item->publishedAt,
        ];
    }

    /**
     * Champs mutables en mise à jour (§4.B) : title, summary, content,
     * author, url/url_hash, content_hash. Jamais `published_at`,
     * `guid`/`dedup_key` (hors chemin « GUID changé »), ni
     * `collected_at`.
     *
     * @return array<string, mixed>
     */
    private function mutableAttributes(
        FeedItemData $item,
        ?string $normalizedUrl,
        string $urlHash,
        string $contentHash,
    ): array {
        return [
            'url' => self::truncate(trim($item->url ?? ''), 2048),
            'url_hash' => $urlHash,
            'content_hash' => $contentHash,
            'title' => self::truncate($item->title, 512),
            'author' => self::truncate($item->author, 255),
            'summary' => self::truncate($item->summary, 65535),
            'content' => $item->content,
        ];
    }

    /**
     * Source de `dedup_key` (§4.B.3) : `guid` exploitable → URL
     * normalisée → repli `title|published_at|summary[:120]`.
     *
     * `guidIsPermalink` signifie « identifiant déclaré exploitable
     * pour la déduplication » (Atom `<id>`, dc:identifier, rdf:about
     * compris — Collecteur §7), pas « le guid est une URL ».
     */
    private function dedupSource(FeedItemData $item, ?string $normalizedUrl): string
    {
        if ($item->guidIsPermalink && $item->guid !== null && trim($item->guid) !== '') {
            return $item->guid;
        }

        if ($normalizedUrl !== null) {
            return $normalizedUrl;
        }

        return implode('|', [
            trim($item->title ?? ''),
            $item->publishedAt?->format('Y-m-d\TH:i:s\Z') ?? '',
            mb_substr(trim($item->summary ?? ''), 0, 120),
        ]);
    }

    /**
     * `content_hash` (P8) : sha256 de title, author, summary, content
     * trimmés (null → chaîne vide), joints par \x1f. Champs volatils
     * exclus : url, guid, published_at.
     */
    public static function contentHash(FeedItemData $item): string
    {
        return hash('sha256', implode("\x1f", [
            trim($item->title ?? ''),
            trim($item->author ?? ''),
            trim($item->summary ?? ''),
            trim($item->content ?? ''),
        ]));
    }

    private static function truncate(?string $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }

        return mb_substr($value, 0, $max);
    }

    /**
     * Anomalie de déduplication : journalisée en ERROR (archi §8) —
     * jamais de contenu d'article ni de donnée personnelle.
     */
    private function logAnomaly(Source $source, string $detail): void
    {
        Log::error('articles.dedup_anomaly', [
            'source_id' => $source->id,
            'detail' => $detail,
        ]);
    }
}

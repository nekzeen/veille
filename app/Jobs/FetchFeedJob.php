<?php

namespace App\Jobs;

use App\Models\Article;
use App\Models\Source;
use App\Services\Feed\ArticleUpsertService;
use App\Services\Feed\Data\ArticleUpsertResult;
use App\Services\Feed\Data\FeedFetchResult;
use App\Services\Feed\Data\ParsedFeed;
use App\Services\Feed\Exceptions\FeedFetchException;
use App\Services\Feed\Exceptions\FeedHttpException;
use App\Services\Feed\Exceptions\FeedParseException;
use App\Services\Feed\FeedClientInterface;
use App\Services\Feed\FeedParserInterface;
use App\Services\KeywordMatcher;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Collecte complète d'une source RSS/Atom (Orchestration §4–§7).
 *
 * Échecs de collecte (`FeedFetchException`, `FeedParseException`) :
 * enregistrés sur la source (compteur, backoff P6, seuil P2), le job
 * se termine normalement. Toute autre exception (erreur systémique,
 * rollback P9) est propagée vers `failed_jobs`, sans toucher aux
 * compteurs.
 *
 * Verrou d'unicité natif (`ShouldBeUnique`) libéré par Laravel en fin
 * de traitement ou d'échec définitif ; `Cache::lock` complémentaire
 * libéré en `finally` par son seul propriétaire.
 */
final class FetchFeedJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const LOCK_SECONDS = 900;

    public int $tries = 1;

    public int $timeout = 60;

    public int $uniqueFor = 900;

    public function __construct(public int $sourceId) {}

    public function uniqueId(): string
    {
        return (string) $this->sourceId;
    }

    public static function lockKey(int $sourceId): string
    {
        return 'feeds:fetch:'.$sourceId;
    }

    public function handle(
        FeedClientInterface $client,
        FeedParserInterface $parser,
        ArticleUpsertService $upserter,
        KeywordMatcher $matcher,
    ): void {
        if ($this->dueSource() === null) {
            return;
        }

        $lock = Cache::lock(self::lockKey($this->sourceId), self::LOCK_SECONDS);
        if (! $lock->get()) {
            Log::info('feeds.fetch.skipped', ['source_id' => $this->sourceId, 'reason' => 'lock_held']);

            return;
        }

        try {
            // Relecture sous verrou : un traitement concurrent a pu
            // collecter la source entre la vérification et l'acquisition.
            $source = $this->dueSource();
            if ($source !== null) {
                $this->collect($source, $client, $parser, $upserter, $matcher);
            }
        } finally {
            $lock->release();
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('feeds.fetch.job_failed', [
            'source_id' => $this->sourceId,
            'exception_class' => $exception !== null ? $exception::class : null,
        ]);
    }

    /** @phpstan-impure */
    private function dueSource(): ?Source
    {
        $source = Source::find($this->sourceId);

        return $source !== null && $source->isEligibleForFetch() && $source->isDue() ? $source : null;
    }

    private function collect(
        Source $source,
        FeedClientInterface $client,
        FeedParserInterface $parser,
        ArticleUpsertService $upserter,
        KeywordMatcher $matcher,
    ): void {
        $startedAt = hrtime(true);

        try {
            $result = $client->fetch($source->feed_url, $source->etag, $source->last_modified);
            $parsed = $result->notModified ? null : $parser->parse($result->body);
        } catch (FeedFetchException|FeedParseException $e) {
            $this->recordFailure($source, $e, $startedAt);

            return;
        }

        $stats = $parsed !== null ? $upserter->upsertBatch($source, $parsed->items) : null;

        // Matching §4.D : uniquement les articles du lot (créés,
        // mis à jour ou revus — un article connu peut rencontrer une
        // veille créée depuis sa première collecte). Les articles
        // sont déjà persistés : une erreur de matching n'est ni un
        // échec de collecte ni une perte de données.
        $matchStats = null;
        if ($stats !== null && $stats->articleIds !== []) {
            $matchStats = $this->matchArticles($source, $stats->articleIds, $matcher);
        }

        $this->recordSuccess($source, $result, $parsed, $stats, $matchStats, $startedAt);
    }

    /**
     * Enregistre les correspondances `article_veille` du lot. En cas
     * d'échec : journalisé avec contexte, le succès de collecte est
     * tout de même enregistré — la prochaine collecte rencontrera les
     * mêmes articles et réparera les correspondances (idempotence).
     *
     * @param  list<int>  $articleIds
     * @return array{examined:int, matched:int, created:int, existing:int}|null
     */
    private function matchArticles(Source $source, array $articleIds, KeywordMatcher $matcher): ?array
    {
        try {
            $articles = Article::whereIn('id', $articleIds)->get();
            $totals = ['examined' => 0, 'matched' => 0, 'created' => 0, 'existing' => 0];

            foreach ($articles as $article) {
                $result = $matcher->matchArticle($article);
                $totals['examined'] += $result->examined;
                $totals['matched'] += $result->matched;
                $totals['created'] += $result->created;
                $totals['existing'] += $result->existing;
            }

            return $totals;
        } catch (Throwable $e) {
            Log::error('feeds.fetch.match_failed', [
                'source_id' => $source->id,
                'article_ids' => $articleIds,
                'exception_class' => $e::class,
                'message' => mb_substr($e->getMessage(), 0, 500),
            ]);

            return null;
        }
    }

    /**
     * @param  array{examined:int, matched:int, created:int, existing:int}|null  $matchStats
     */
    private function recordSuccess(
        Source $source,
        FeedFetchResult $result,
        ?ParsedFeed $parsed,
        ?ArticleUpsertResult $stats,
        ?array $matchStats,
        int $startedAt,
    ): void {
        $now = now();
        $attributes = [
            'last_fetched_at' => $now,
            'last_success_at' => $now,
            'fail_count' => 0,
            'last_error' => null,
        ];

        // Validateur absent (ou hors gabarit de colonne) : la valeur
        // stockée est conservée.
        foreach (['etag' => $result->etag, 'last_modified' => $result->lastModified] as $column => $value) {
            if ($value !== null && $value !== '' && mb_strlen($value) <= 255) {
                $attributes[$column] = $value;
            }
        }

        Source::whereKey($source->id)->update($attributes);

        if ($parsed !== null) {
            $this->enrichDescriptiveMetadata($source, $parsed);
        }

        Log::info('feeds.fetch.succeeded', [
            'source_id' => $source->id,
            'result' => 'success',
            'http_status' => $result->statusCode,
            'duration_ms' => self::elapsedMs($startedAt),
            'received' => $stats->received ?? 0,
            'created' => $stats->created ?? 0,
            'updated' => $stats->updated ?? 0,
            'unchanged' => $stats->unchanged ?? 0,
            'skipped' => $stats->skipped ?? 0,
            'collisions' => $stats->collisions ?? 0,
            'match_created' => $matchStats['created'] ?? null,
            'match_existing' => $matchStats['existing'] ?? null,
            'fail_count' => 0,
            'next_due_at' => $now->copy()->addMinutes(Source::backoffMinutes(0))->toIso8601String(),
        ]);
    }

    private function recordFailure(Source $source, FeedFetchException|FeedParseException $e, int $startedAt): void
    {
        $errorClass = class_basename($e);

        // Mise à jour atomique unique. `is_active` est affecté avant
        // `fail_count` : MariaDB évalue les affectations de gauche à
        // droite sur la ligne déjà modifiée, SQLite sur l'ancienne.
        Source::whereKey($source->id)->update([
            'is_active' => DB::raw('CASE WHEN fail_count + 1 >= '.Source::DEACTIVATION_THRESHOLD.' THEN 0 ELSE is_active END'),
            'fail_count' => DB::raw('fail_count + 1'),
            'last_fetched_at' => now(),
            'last_error' => mb_substr($errorClass.': '.$e->getMessage(), 0, 500),
        ]);

        $source->refresh();
        $deactivated = ! $source->is_active && $source->fail_count >= Source::DEACTIVATION_THRESHOLD;

        Log::warning('feeds.fetch.failed', [
            'source_id' => $source->id,
            'result' => 'failure',
            'error_class' => $errorClass,
            'http_status' => $e instanceof FeedHttpException ? $e->statusCode : null,
            'duration_ms' => self::elapsedMs($startedAt),
            'fail_count' => $source->fail_count,
            'next_due_at' => $source->is_active ? $source->dueAt()?->toIso8601String() : null,
        ]);

        if ($deactivated) {
            Log::warning('feeds.fetch.source_deactivated', [
                'source_id' => $source->id,
                'fail_count' => $source->fail_count,
                'reason' => 'consecutive_failures_threshold',
            ]);
        }
    }

    /**
     * P12 : nom, site et description renseignés uniquement si vides.
     * La condition est portée par l'UPDATE pour ne jamais écraser une
     * valeur écrite entre-temps.
     */
    private function enrichDescriptiveMetadata(Source $source, ParsedFeed $parsed): void
    {
        $candidates = [
            'name' => self::clip($parsed->title, 255),
            'site_url' => self::httpUrl($parsed->siteUrl),
            'description' => self::clip($parsed->description, 500),
        ];

        foreach ($candidates as $column => $value) {
            if ($value === null || filled($source->getAttribute($column))) {
                continue;
            }

            Source::whereKey($source->id)
                ->where(fn ($query) => $query->whereNull($column)->orWhere($column, ''))
                ->update([$column => $value]);
        }
    }

    private static function clip(?string $value, int $max): ?string
    {
        $value = trim($value ?? '');

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    private static function httpUrl(?string $url): ?string
    {
        $url = trim($url ?? '');
        if ($url === '' || strlen($url) > 512 || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) ? $url : null;
    }

    private static function elapsedMs(int $startedAt): int
    {
        return (int) round((hrtime(true) - $startedAt) / 1_000_000);
    }
}

<?php

namespace App\Services;

use App\Models\Source;
use App\Models\User;
use App\Models\UserSource;
use App\Services\Feed\Detection\DetectionResult;
use App\Services\Feed\Detection\SourceDetector;
use App\Services\Feed\Detection\SourceNotCollectableException;
use App\Services\Feed\Html\HtmlExtractor;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SourceSubscriptionService
{
    public function __construct(
        private readonly SourceDetector $detector,
        private readonly SourceLifecycleService $lifecycle,
    ) {}

    /**
     * Ajout d'une source personnelle via la détection (M8.5) : le
     * serveur redétecte l'URL soumise — rien de ce qui provient du
     * navigateur (connecteur, fetch_url, état) n'est fiable ; seule
     * la configuration HTML éventuelle est filtrée puis appliquée.
     *
     * @param  array<string, mixed>|null  $htmlConfig
     * @return array{0: Source, 1: UserSource, 2: DetectionResult}
     *
     * @throws SourceNotCollectableException détection en échec ou
     *                                       page HTML sans article extractible
     */
    public function addDetectedSource(User $user, string $submittedUrl, ?array $htmlConfig = null, ?int $themeId = null): array
    {
        $submittedUrl = trim($submittedUrl);
        $config = self::sanitizeHtmlConfig($htmlConfig);
        $detection = $this->detector->detect($submittedUrl, $config);

        if (! $detection->isSuccessful() || ! $detection->connectorAvailable) {
            throw new SourceNotCollectableException($detection);
        }

        $connector = $detection->connector ?? 'rss';
        $parsed = $detection->parsed;
        if ($connector === 'html' && ($parsed === null || $parsed->items === [])) {
            throw new SourceNotCollectableException(
                DetectionResult::failure('unrecognized', $submittedUrl),
            );
        }

        $fetchUrl = self::normalizeFeedUrl($detection->fetchUrl ?? $submittedUrl);
        $withColumns = Schema::hasColumn('sources', 'connector');
        // Colonnes M8 absentes (migration de production non appliquée) :
        // seuls les flux RSS/Atom sont enregistrables — une page HTML ou
        // un JSON Feed deviendrait sinon une « source RSS » en échec.
        if (! $withColumns && ! in_array($connector, ['rss', 'atom'], true)) {
            throw new SourceNotCollectableException(
                DetectionResult::failure('unsupported_content_type', $submittedUrl),
            );
        }

        return DB::transaction(function () use ($user, $submittedUrl, $fetchUrl, $connector, $config, $detection, $withColumns, $themeId): array {
            $source = Source::where('feed_url', $fetchUrl)->first()
                ?? ($withColumns ? Source::where('fetch_url', $fetchUrl)->first() : null);

            if ($source === null) {
                // M13.2b — toute NOUVELLE source validée est publiée au
                // catalogue commun avec sa thématique ; une source
                // existante n'est ni re-publiée ni re-thématisée (les
                // historiques privés restent privés).
                $attributes = [
                    'feed_url' => $fetchUrl,
                    'name' => $detection->parsed?->title
                        ?: (parse_url($fetchUrl, PHP_URL_HOST) ?: $fetchUrl),
                    'site_url' => $detection->parsed?->siteUrl,
                    'theme_id' => $themeId,
                    'is_catalogue' => true,
                    'created_at' => now('UTC'),
                    'updated_at' => now('UTC'),
                ];
                // Garde temporaire (M8.2.1) : colonnes absentes tant que
                // la migration n'est pas appliquée en production.
                if ($withColumns) {
                    $attributes += [
                        'submitted_url' => $submittedUrl,
                        'fetch_url' => $fetchUrl,
                        'connector' => $connector,
                        'config' => $connector === 'html' ? $config : null,
                        'config_state' => 'validated',
                        'detection' => $detection->toArray(),
                    ];
                }
                try {
                    $source = Source::create($attributes);
                } catch (QueryException $e) {
                    if (! self::isUniqueViolation($e)) {
                        throw $e;
                    }
                    // Le doublon UNIQUE prouve l'existence : la ligne
                    // concurrente doit se relire, sinon erreur réelle.
                    $source = Source::where('feed_url', $fetchUrl)->firstOrFail();
                }
            }

            // Source existante : jamais d'écrasement de sa configuration.
            return [$source, $this->firstOrCreateSubscription($user, $source), $detection];
        });
    }

    /**
     * Configuration HTML filtrée : seuls les champs documentés
     * subsistent, sélecteurs bornés et validés (sous-ensemble CSS),
     * stratégie ramenée à `auto` sans sélecteur `item`.
     *
     * @param  array<string, mixed>|null  $config
     * @return array<string, mixed>|null
     */
    public static function sanitizeHtmlConfig(?array $config): ?array
    {
        if ($config === null) {
            return null;
        }

        $selectors = [];
        foreach (['item', 'link', 'title', 'summary', 'date'] as $key) {
            $value = $config['selectors'][$key] ?? null;
            if (is_string($value) && trim($value) !== ''
                && HtmlExtractor::isValidSelector($value)) {
                $selectors[$key] = mb_substr(trim($value), 0, 300);
            }
        }

        $strategy = ($config['strategy'] ?? null) === 'selectors' && isset($selectors['item'])
            ? 'selectors'
            : 'auto';
        $enrichPages = max(0, min(HtmlExtractor::MAX_ENRICH_PAGES, (int) ($config['enrich_pages'] ?? 0)));

        if ($strategy === 'auto' && $selectors === [] && $enrichPages === 0) {
            return null; // rien à mémoriser : configuration par défaut
        }

        return [
            'version' => 1,
            'strategy' => $strategy,
            'enrich_pages' => $enrichPages,
            'selectors' => $selectors,
        ];
    }

    /**
     * Abonnement de l'utilisateur à une source existante (catalogue ou déjà enregistrée).
     *
     * @throws \RuntimeException si la source n'est pas accessible à l'utilisateur.
     */
    public function subscribe(User $user, Source $source): UserSource
    {
        if (! $source->is_catalogue
            && ! $source->userSources()->where('user_id', $user->id)->exists()) {
            throw new \RuntimeException('Source non accessible.');
        }

        return $this->firstOrCreateSubscription($user, $source);
    }

    /**
     * Ajout d'une source personnelle par URL : réutilise la source technique
     * existante ou la crée (is_catalogue = false), puis crée l'abonnement.
     *
     * @return array{0: Source, 1: UserSource}
     */
    public function addPersonalSource(User $user, string $feedUrl): array
    {
        $normalized = self::normalizeFeedUrl($feedUrl);

        return DB::transaction(function () use ($user, $normalized): array {
            $source = Source::where('feed_url', $normalized)->first();

            if ($source === null) {
                try {
                    $attributes = [
                        'feed_url' => $normalized,
                        'name' => parse_url($normalized, PHP_URL_HOST) ?: $normalized,
                        'is_catalogue' => false,
                        'created_at' => now('UTC'),
                        'updated_at' => now('UTC'),
                    ];
                    // Garde temporaire : colonnes M8 absentes tant que la
                    // migration n'est pas appliqu�e en production.
                    if (Schema::hasColumn('sources', 'connector')) {
                        $attributes += [
                            'submitted_url' => $normalized,
                            'fetch_url' => $normalized,
                            'connector' => 'rss',
                            'config_state' => 'validated',
                        ];
                    }
                    $source = Source::create($attributes);
                } catch (QueryException $e) {
                    // Seule une violation UNIQUE (23000/1062) est un doublon
                    // concurrent : relire l'existant. Toute autre erreur SQL
                    // est relancée telle quelle, jamais masquée.
                    if (! self::isUniqueViolation($e)) {
                        throw $e;
                    }
                    $source = Source::where('feed_url', $normalized)->first();
                    if ($source === null) {
                        throw $e;
                    }
                }
            }

            return [$source, $this->firstOrCreateSubscription($user, $source)];
        });
    }

    /**
     * Désabonnement : supprime uniquement l'abonnement de l'utilisateur,
     * plus les usages de ses propres veilles (DataModel §4.A), dans une
     * transaction. Jamais la source technique ni les articles.
     */
    public function unsubscribe(User $user, UserSource $subscription): void
    {
        DB::transaction(function () use ($user, $subscription): void {
            $source = $subscription->source;
            $sourceId = $subscription->source_id;

            // Retrait de la source des veilles de l'utilisateur, puis
            // correspondances liées (difference d'ensembles, §4.A/§4.D).
            DB::table('source_veille')
                ->where('user_id', $user->id)->where('source_id', $sourceId)->delete();
            DB::table('article_veille')
                ->where('user_id', $user->id)
                ->whereIn('article_id', function ($q) use ($sourceId): void {
                    $q->select('id')->from('articles')->where('source_id', $sourceId);
                })->delete();

            $subscription->delete();

            $this->refreshOrphanFlag($source);
        });
    }

    /**
     * Marque `orphaned_at` si la source est totalement orpheline (D16) ;
     * NULL sinon. Ne supprime jamais la source elle-même.
     */
    private function refreshOrphanFlag(Source $source): void
    {
        $this->lifecycle->reevaluate($source);
    }

    /**
     * Crée l'abonnement si absent ; tolère le doublon concurrent sur
     * UNIQUE(user_id, source_id).
     */
    private function firstOrCreateSubscription(User $user, Source $source): UserSource
    {
        $existing = UserSource::where('user_id', $user->id)
            ->where('source_id', $source->id)->first();
        if ($existing !== null) {
            return $existing;
        }

        try {
            return UserSource::create([
                'user_id' => $user->id,
                'source_id' => $source->id,
                'created_at' => now('UTC'),
            ]);
        } catch (QueryException $e) {
            if (! self::isUniqueViolation($e)) {
                throw $e;
            }
            $existing = UserSource::where('user_id', $user->id)
                ->where('source_id', $source->id)->first();
            if ($existing === null) {
                throw $e;
            }

            return $existing;
        }
    }

    /**
     * Vrai uniquement pour une violation de contrainte UNIQUE :
     * SQLSTATE 23000 + errno MariaDB 1062. Sur SQLite (tests), le code 19
     * est générique — le message « UNIQUE constraint failed » distingue
     * les vrais doublons des autres erreurs d'intégrité.
     */
    public static function isUniqueViolation(QueryException $e): bool
    {
        $info = $e->errorInfo;
        if (($info[0] ?? null) !== '23000') {
            return false;
        }

        if (DB::connection()->getDriverName() === 'sqlite') {
            return (int) ($info[1] ?? 0) === 19
                && str_contains($e->getMessage(), 'UNIQUE constraint failed');
        }

        return (int) ($info[1] ?? 0) === 1062;
    }

    /**
     * Normalisation feed_url (DataModel §4.B.1) : schéma+hôte minuscules,
     * port par défaut retiré, fragment supprimé, slash racine normalisé,
     * paramètres de requête conservés.
     */
    public static function normalizeFeedUrl(string $url): string
    {
        $parts = parse_url(trim($url));
        if ($parts === false) {
            return $url;
        }

        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower($parts['host'] ?? '');
        $port = $parts['port'] ?? null;
        $path = $parts['path'] ?? '';
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';

        if ($port === 80 && $scheme === 'http' || $port === 443 && $scheme === 'https') {
            $port = null;
        }

        $authority = $host.($port !== null ? ':'.$port : '');
        if ($path === '' || $path === '/') {
            $path = '/';
        }

        return $scheme.'://'.$authority.$path.$query;
    }
}

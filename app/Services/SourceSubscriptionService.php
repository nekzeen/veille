<?php

namespace App\Services;

use App\Models\Source;
use App\Models\User;
use App\Models\UserSource;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class SourceSubscriptionService
{
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
                    $source = Source::create([
                        'feed_url' => $normalized,
                        'name' => parse_url($normalized, PHP_URL_HOST) ?: $normalized,
                        'is_catalogue' => false,
                        'created_at' => now('UTC'),
                        'updated_at' => now('UTC'),
                    ]);
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
        $orphan = ! $source->is_catalogue
            && ! $source->userSources()->exists()
            && ! DB::table('source_veille')->where('source_id', $source->id)->exists()
            && ! DB::table('articles')->where('source_id', $source->id)->exists();

        $source->update(['orphaned_at' => $orphan ? now('UTC') : null]);
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

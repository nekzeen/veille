<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Article;
use App\Models\ArticleVeille;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * M12.11 (corrigée) — Purge de rétention des articles.
 *
 * Étape A : retrait des `article_veille` > 6 mois + datation atomique
 *           du dernier détachement (`articles.last_detached_at`).
 * Étape B : purge des orphelins détachés depuis > 7 jours, sans
 *           donnée personnelle significative — conditions revérifiées
 *           dans le DELETE lui-même (atomique par transaction de lot).
 *
 * SÉCURITÉ : le mode par défaut est DRY-RUN — aucune suppression sans
 * `--execute`. `--dry-run` et `--execute` sont incompatibles.
 *
 * Jamais `created_at` comme substitut au dernier détachement.
 */
class ArticlesPurgeCommand extends Command
{
    protected $signature = 'articles:purge
        {--dry-run : Compteurs uniquement (défaut)}
        {--execute : Exécuter réellement les suppressions}';

    protected $description = 'Rétention articles : A = associations >6mo + datation détachement ; B = purge orphelins >7j';

    private const CUTOFF_MONTHS = 6;

    private const GRACE_DAYS = 7;

    private const CHUNK = 500;

    private const EFFECTIVE_DATE = 'COALESCE(articles.published_at, articles.collected_at)';

    public function handle(): int
    {
        if ($this->option('dry-run') && $this->option('execute')) {
            $this->error('Options incompatibles : --dry-run et --execute ne peuvent pas être combinées.');

            return self::FAILURE;
        }
        $exec = (bool) $this->option('execute');
        $this->info($exec ? 'MODE EXECUTE' : 'MODE DRY-RUN (aucune écriture)');

        $cutoff = now('UTC')->subMonths(self::CUTOFF_MONTHS);
        $grace = now('UTC')->subDays(self::GRACE_DAYS);

        // ---------- A : associations périmées ----------
        $staleIds = DB::table('article_veille')
            ->join('articles', 'articles.id', '=', 'article_veille.article_id')
            ->whereRaw(self::EFFECTIVE_DATE.' < ?', [$cutoff])
            ->orderBy('article_veille.id')
            ->limit(self::CHUNK)
            ->pluck('article_veille.id');

        $this->info("A — lot associations périmées : {$staleIds->count()}");
        $detachedMarked = 0;
        if ($exec && $staleIds->isNotEmpty()) {
            DB::transaction(function () use ($staleIds, &$detachedMarked): void {
                $articleIds = ArticleVeille::whereIn('id', $staleIds)->pluck('article_id');
                ArticleVeille::whereIn('id', $staleIds)->delete();
                // Datation atomique : articles sans AUCUNE association restante.
                // Datation inconditionnelle : le DERNIER détachement
                // est celui qui vient d'avoir lieu — jamais une date
                // plus ancienne échappée à une précédente déassociation.
                $detachedMarked = Article::whereIn('id', $articleIds)
                    ->whereNotExists(fn ($q) => $q->selectRaw(1)->from('article_veille')
                        ->whereColumn('article_veille.article_id', 'articles.id'))
                    ->update(['last_detached_at' => now('UTC')]);
            });
        }
        $this->info("A — derniers détachements datés : $detachedMarked");

        // Orphelins préexistants (détachés avant ce mécanisme ou jamais
        // associés) : première observation = nouvelle grâce de 7 j.
        // Orphelins préexistants : même date de référence que l'étape A.
        $preOrphans = Article::whereNull('last_detached_at')
            ->whereRaw(self::EFFECTIVE_DATE.' < ?', [$cutoff])
            ->whereNotExists(fn ($q) => $q->selectRaw(1)->from('article_veille')
                ->whereColumn('article_veille.article_id', 'articles.id'))
            ->count();
        $this->info("Orphelins préexistants à dater (grâce fraîche) : $preOrphans");
        if ($exec && $preOrphans > 0) {
            Article::whereNull('last_detached_at')
                ->whereRaw(self::EFFECTIVE_DATE.' < ?', [$cutoff])
                ->whereNotExists(fn ($q) => $q->selectRaw(1)->from('article_veille')
                    ->whereColumn('article_veille.article_id', 'articles.id'))
                ->limit(self::CHUNK)
                ->update(['last_detached_at' => now('UTC')]);
        }

        // ---------- B : purge — conditions revérifiées dans le DELETE ----------
        $toPurge = $this->eligible($cutoff, $grace)->count();
        $this->info('B — orphelins détachés > '.self::GRACE_DAYS."j éligibles : $toPurge");

        $purged = 0;
        if ($exec && $toPurge > 0) {
            $this->eligible($cutoff, $grace)->orderBy('id')->limit(self::CHUNK)
                ->pluck('id')->chunk(self::CHUNK)
                ->each(function ($ids) use (&$purged, $cutoff, $grace): void {
                    DB::transaction(function () use ($ids, &$purged, $cutoff, $grace): void {
                        // Revérification atomique dans la transaction.
                        $purged += Article::whereIn('id', $ids)
                            ->whereRaw($this->eligibilitySql(), [$cutoff, $grace])
                            ->delete();
                    });
                });
        }
        $this->info("B — supprimés : $purged");
        Log::info('articles:purge '.($exec ? 'EXECUTE' : 'DRY-RUN')." A:$detachedMarked B:$purged/$toPurge");

        return self::SUCCESS;
    }

    /** Requête d'éligibilité commune (count + ids + delete atomique). */
    private function eligible($cutoff, $grace): Builder
    {
        return Article::query()->whereRaw($this->eligibilitySql(), [$cutoff, $grace]);
    }

    /**
     * Conditions impératives, revérifiées à chaque suppression :
     * orphelin + détaché > grace + date référence > cutoff +
     * aucun favori/archive/note — NOT EXISTS évalués à l'exécution.
     */
    private function eligibilitySql(): string
    {
        return self::EFFECTIVE_DATE.' < ?
            AND articles.last_detached_at IS NOT NULL
            AND articles.last_detached_at < ?
            AND NOT EXISTS (SELECT 1 FROM article_veille WHERE article_veille.article_id = articles.id)
            AND NOT EXISTS (SELECT 1 FROM article_user WHERE article_user.article_id = articles.id
                AND (article_user.is_favorite OR article_user.is_archived OR article_user.note IS NOT NULL))';
    }
}

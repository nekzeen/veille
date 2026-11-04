<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Article;
use App\Models\UserExclusion;
use App\Services\Exclusions\ExclusionMatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * M9.4 — application rétroactive d'une règle d'exclusion aux articles
 * déjà associés aux veilles de son propriétaire (conception §9.2/§9.4).
 *
 * Idempotent : `INSERT IGNORE` + recalcul des correspondances de la
 * règle seule ; les justifications des autres règles et les masquages
 * manuels (exclusion_id NULL) ne sont jamais touchés. Un verrou
 * `Cache::lock` par règle interdit deux exécutions concurrentes.
 */
class ApplyExclusionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const CHUNK = 500;

    private const LOCK_TTL = 600;

    public function __construct(
        public readonly int $userId,
        public readonly int $exclusionId,
    ) {}

    public function handle(ExclusionMatcher $matcher): void
    {
        $lock = Cache::lock('exclusion-apply:'.$this->exclusionId, self::LOCK_TTL);
        if (! $lock->get()) {
            return; // exécution concurrente → la première gagne
        }

        try {
            $rule = UserExclusion::where('id', $this->exclusionId)
                ->where('user_id', $this->userId)->first();
            if ($rule === null) {
                return;
            }

            Article::query()
                ->join('article_veille', 'article_veille.article_id', '=', 'articles.id')
                ->where('article_veille.user_id', $this->userId)
                ->select('articles.*')
                ->distinct()
                ->chunkById(self::CHUNK, function ($articles) use ($matcher, $rule): void {
                    $matchedIds = [];
                    foreach ($articles as $article) {
                        $haystack = implode(' ', array_filter([
                            $article->title, $article->summary, $article->content,
                        ]));
                        if (in_array($rule->id, $matcher->justifications(
                            $this->userId, $article->url, $haystack,
                        ), true)) {
                            $matchedIds[] = $article->id;
                        }
                    }

                    $chunkIds = $articles->modelKeys();
                    DB::transaction(function () use ($chunkIds, $matchedIds, $rule): void {
                        // Insert AVANT delete : le masquage reste continu.
                        $now = now('UTC');
                        DB::table('article_user_hidden')->insertOrIgnore(array_map(
                            fn (int $articleId): array => [
                                'user_id' => $this->userId,
                                'article_id' => $articleId,
                                'exclusion_id' => $rule->id,
                                'created_at' => $now,
                            ],
                            $matchedIds,
                        ));

                        $stale = DB::table('article_user_hidden')
                            ->where('exclusion_id', $rule->id)
                            ->whereIn('article_id', $chunkIds);
                        if ($matchedIds !== []) {
                            $stale->whereNotIn('article_id', $matchedIds);
                        }
                        $stale->delete();
                    });
                }, 'articles.id', 'id');
        } finally {
            $lock->release();
        }
    }
}

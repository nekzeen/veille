<?php

namespace Tests\Feature;

use App\Jobs\ApplyExclusionJob;
use App\Models\Article;
use App\Models\ArticleVeille;
use App\Models\Source;
use App\Models\User;
use App\Models\UserExclusion;
use App\Models\Veille;
use App\Services\ArticleVeilleWriter;
use App\Services\Exclusions\ArticleVisibility;
use App\Services\Exclusions\ExclusionMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** M9.4 — job rétroactif, réconciliation, verrou, filtre digest. */
class ExclusionRetroactiveTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $user = User::factory()->create();
        $veille = Veille::factory()->create(['user_id' => $user->id]);

        return [$user, $veille];
    }

    private function article(string $url, string $title = 'Titre'): Article
    {
        $guid = 'g-'.md5($url.$title);

        return Article::create([
            'source_id' => Source::factory()->create()->id,
            'guid' => $guid, 'dedup_key' => hash('sha256', $guid),
            'url' => $url, 'url_hash' => hash('sha256', $url),
            'title' => $title, 'summary' => 'Résumé', 'content' => '<p>Corps</p>',
            'collected_at' => now('UTC'),
        ]);
    }

    private function hidden(?int $exclusionId = null): int
    {
        $q = DB::table('article_user_hidden');

        return $exclusionId === null ? $q->count() : $q->where('exclusion_id', $exclusionId)->count();
    }

    private function job(int $userId, int $exclusionId): void
    {
        (new ApplyExclusionJob($userId, $exclusionId))->handle(app(ExclusionMatcher::class));
    }

    public function test_regle_creee_apres_association_marque_les_articles(): void
    {
        [$user, $veille] = $this->fixture();
        $a = $this->article('https://indeed.com/offre/1');
        app(ArticleVeilleWriter::class)->record($veille, $a->id, 'kw');
        $this->assertSame(0, $this->hidden());

        $rule = UserExclusion::create(['user_id' => $user->id, 'kind' => 'url', 'pattern' => 'indeed.com']);
        $this->job($user->id, $rule->id);
        $this->assertSame(1, $this->hidden($rule->id));
        // Idempotent.
        $this->job($user->id, $rule->id);
        $this->assertSame(1, $this->hidden($rule->id));
    }

    public function test_modification_recalcule_ses_seules_justifications(): void
    {
        [$user, $veille] = $this->fixture();
        $a1 = $this->article('https://indeed.com/1');
        $a2 = $this->article('https://monster.com/2');
        $w = app(ArticleVeilleWriter::class);
        $w->record($veille, $a1->id, 'kw');
        $w->record($veille, $a2->id, 'kw');

        $rule = UserExclusion::create(['user_id' => $user->id, 'kind' => 'url', 'pattern' => 'indeed.com']);
        $this->job($user->id, $rule->id);
        $this->assertSame(1, $this->hidden($rule->id));

        $rule->update(['pattern' => 'monster.com']);
        $this->job($user->id, $rule->id);
        // indeed retiré, monster ajouté — une seule ligne déplacée.
        $this->assertSame(1, $this->hidden($rule->id));
        $this->assertSame($a2->id, DB::table('article_user_hidden')->value('article_id'));
    }

    public function test_verrou_bloque_la_concurrence_et_se_libere(): void
    {
        [$user, $veille] = $this->fixture();
        $a = $this->article('https://indeed.com/1');
        app(ArticleVeilleWriter::class)->record($veille, $a->id, 'kw');
        $rule = UserExclusion::create(['user_id' => $user->id, 'kind' => 'url', 'pattern' => 'indeed.com']);

        $lock = Cache::lock('exclusion-apply:'.$rule->id, 60);
        $this->assertTrue($lock->get());
        $this->job($user->id, $rule->id); // verrou tenu → aucun travail
        $this->assertSame(0, $this->hidden($rule->id));
        $lock->release();

        $this->job($user->id, $rule->id);
        $this->assertSame(1, $this->hidden($rule->id));
        // Après le job, le verrou est libéré : ré-acquisition possible.
        $this->assertTrue(Cache::lock('exclusion-apply:'.$rule->id, 60)->get());
    }

    public function test_lots_multiples_et_masquage_manuel_preserve(): void
    {
        [$user, $veille] = $this->fixture();
        $w = app(ArticleVeilleWriter::class);
        $firstId = null;
        for ($i = 1; $i <= 505; $i++) {
            $a = $this->article("https://indeed.com/offre/$i");
            $firstId ??= $a->id;
            $w->record($veille, $a->id, 'kw');
        }
        // Ligne de masquage manuel (exclusion_id NULL) sur le 1er article.
        DB::table('article_user_hidden')->insert([
            'user_id' => $user->id, 'article_id' => $firstId, 'exclusion_id' => null,
            'created_at' => now('UTC'),
        ]);

        $rule = UserExclusion::create(['user_id' => $user->id, 'kind' => 'url', 'pattern' => 'indeed.com']);
        $this->job($user->id, $rule->id);

        $this->assertSame(505, $this->hidden($rule->id)); // > 1 lot de 500
        $this->assertSame(1, DB::table('article_user_hidden')->whereNull('exclusion_id')->count());
    }

    public function test_reactivation_masque_les_articles_collectes_pendant_inactivation(): void
    {
        [$user, $veille] = $this->fixture();
        $w = app(ArticleVeilleWriter::class);
        $rule = UserExclusion::create(['user_id' => $user->id, 'kind' => 'url', 'pattern' => 'indeed.com']);

        $a1 = $this->article('https://indeed.com/1');
        $w->record($veille, $a1->id, 'kw');
        $this->assertSame(1, $this->hidden($rule->id));

        // Désactivation : l'ancienne ligne est ignorée côté lecture.
        $rule->update(['is_active' => false]);

        // Article collecté PENDANT l'inactivité : pas de justification.
        $a2 = $this->article('https://indeed.com/2');
        $w->record($veille, $a2->id, 'kw');
        $this->assertCount(1, DB::table('article_user_hidden')->where('exclusion_id', $rule->id)->get());

        $visible = fn (Article $a): bool => ArticleVeille::query()
            ->where('article_veille.user_id', $user->id)
            ->where('article_veille.article_id', $a->id)
            ->where(fn ($q) => ArticleVisibility::apply($q, $user->id, 'article_veille.article_id'))
            ->exists();
        $this->assertTrue($visible($a1));
        $this->assertTrue($visible($a2));

        // Réactivation → job (comme dans UserExclusionController::toggle).
        $rule->update(['is_active' => true]);
        $this->job($user->id, $rule->id);

        $this->assertFalse($visible($a1));
        $this->assertFalse($visible($a2));
        $this->assertSame(2, $this->hidden($rule->id));
    }

    public function test_desactivation_ne_declenche_pas_de_job(): void
    {
        Bus::fake();
        $user = User::factory()->create();
        $rule = UserExclusion::create(['user_id' => $user->id, 'kind' => 'url', 'pattern' => 'indeed.com']);

        // toggle via la route : off puis on — seul « on » dispatche.
        $this->actingAs($user)->patch(route('exclusions.toggle', $rule));
        Bus::assertNotDispatched(ApplyExclusionJob::class);
        $this->actingAs($user)->patch(route('exclusions.toggle', $rule->fresh()));
        Bus::assertDispatched(ApplyExclusionJob::class);
    }

    public function test_regle_supprimee_job_est_sans_effet(): void
    {
        [$user, $veille] = $this->fixture();
        $a = $this->article('https://indeed.com/1');
        app(ArticleVeilleWriter::class)->record($veille, $a->id, 'kw');

        $rule = UserExclusion::create(['user_id' => $user->id, 'kind' => 'url', 'pattern' => 'indeed.com']);
        $ruleId = $rule->id;
        $rule->delete();
        $this->job($user->id, $ruleId);
        $this->assertSame(0, $this->hidden());
    }

    public function test_isolation_job_ne_touche_pas_autrui(): void
    {
        [$user, $veille] = $this->fixture();
        [$other, $veille2] = $this->fixture();
        $a = $this->article('https://indeed.com/1');
        $w = app(ArticleVeilleWriter::class);
        $w->record($veille, $a->id, 'kw');
        $w->record($veille2, $a->id, 'kw');

        $rule = UserExclusion::create(['user_id' => $user->id, 'kind' => 'url', 'pattern' => 'indeed.com']);
        $this->job($user->id, $rule->id);

        $this->assertSame(1, $this->hidden());
        $this->assertSame($user->id, DB::table('article_user_hidden')->value('user_id'));
    }
}

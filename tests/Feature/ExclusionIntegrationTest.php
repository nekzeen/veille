<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\ArticleVeille;
use App\Models\Source;
use App\Models\User;
use App\Models\UserExclusion;
use App\Models\Veille;
use App\Services\ArticleVeilleWriter;
use App\Services\Exclusions\ArticleVisibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** M9.3 — exclusions à l'écriture `article_veille` + filtrage lecture. */
class ExclusionIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $user = User::factory()->create();
        $source = Source::factory()->create();
        $veille = Veille::factory()->create(['user_id' => $user->id]);
        DB::table('source_veille')->insert([
            'veille_id' => $veille->id, 'source_id' => $source->id,
            'user_id' => $user->id,
        ]);

        return [$user, $source, $veille];
    }

    private function exclusion(User $u, string $kind, string $pattern, bool $active = true): UserExclusion
    {
        return UserExclusion::create([
            'user_id' => $u->id, 'kind' => $kind, 'pattern' => $pattern, 'is_active' => $active,
        ]);
    }

    private function article(string $url, string $title = 'Titre', ?string $summary = 'Résumé'): Article
    {
        $guid = 'g-'.md5($url);

        return Article::create([
            'source_id' => Source::factory()->create()->id,
            'guid' => $guid, 'dedup_key' => hash('sha256', $guid),
            'url' => $url, 'url_hash' => hash('sha256', $url),
            'title' => $title, 'summary' => $summary,
            'content' => '<p>Corps</p>', 'collected_at' => now('UTC'),
        ]);
    }

    private function hiddenCount(): int
    {
        return DB::table('article_user_hidden')->count();
    }

    public function test_collecte_sans_exclusion_aucune_ligne(): void
    {
        [$user, , $veille] = $this->fixture();
        $a = $this->article('https://ex.fr/1');
        app(ArticleVeilleWriter::class)->record($veille, $a->id, 'kw');
        $this->assertSame(0, $this->hiddenCount());
    }

    public function test_exclusion_url_justification_enregistree(): void
    {
        [$user, , $veille] = $this->fixture();
        $rule = $this->exclusion($user, 'url', 'indeed.com');
        $a = $this->article('https://indeed.com/offre/9');
        app(ArticleVeilleWriter::class)->record($veille, $a->id, 'kw');

        $row = DB::table('article_user_hidden')->sole();
        $this->assertSame($user->id, $row->user_id);
        $this->assertSame($a->id, $row->article_id);
        $this->assertSame($rule->id, $row->exclusion_id);
    }

    public function test_exclusion_keyword_justification(): void
    {
        [$user, , $veille] = $this->fixture();
        $rule = $this->exclusion($user, 'keyword', 'contenu sponsorisé');
        $a = $this->article('https://ex.fr/9', 'Un contenu sponsorise ici');
        app(ArticleVeilleWriter::class)->record($veille, $a->id, 'kw');
        $this->assertSame($rule->id, DB::table('article_user_hidden')->value('exclusion_id'));
    }

    public function test_plusieurs_justifications_et_collecte_repetee(): void
    {
        [$user, , $veille] = $this->fixture();
        $this->exclusion($user, 'url', 'indeed.com');
        $this->exclusion($user, 'keyword', 'emploi');
        $a = $this->article('https://indeed.com/1', 'offre emploi');

        $w = app(ArticleVeilleWriter::class);
        $w->record($veille, $a->id, 'kw');
        $this->assertCount(2, DB::table('article_user_hidden')->get());
        $w->record($veille, $a->id, 'kw'); // déjà associé → rien d'ajouté
        $this->assertCount(2, DB::table('article_user_hidden')->get());
    }

    public function test_isolation_entre_utilisateurs(): void
    {
        [$user, , $veille] = $this->fixture();
        [$other, $s2, $v2] = $this->fixture();
        $this->exclusion($user, 'url', 'indeed.com');
        $a = $this->article('https://indeed.com/1');

        $w = app(ArticleVeilleWriter::class);
        $w->record($veille, $a->id, 'kw');
        $w->record($v2, $a->id, 'kw'); // même article, autre user

        $this->assertSame(1, $this->hiddenCount()); // seulement user
        $this->assertSame(
            $user->id,
            DB::table('article_user_hidden')->value('user_id'),
        );
    }

    public function test_filtrage_lecture_regle_active_vs_inactive(): void
    {
        [$user, , $veille] = $this->fixture();
        $rule = $this->exclusion($user, 'url', 'indeed.com');
        $a = $this->article('https://indeed.com/1');
        app(ArticleVeilleWriter::class)->record($veille, $a->id, 'kw');

        $this->assertSame(
            0,
            ArticleVeille::query()
                ->where('article_veille.user_id', $user->id)
                ->where(fn ($q) => ArticleVisibility::apply($q, $user->id, 'article_veille.article_id'))
                ->count(),
        );

        $rule->update(['is_active' => false]);
        $this->assertSame(
            1,
            ArticleVeille::query()
                ->where('article_veille.user_id', $user->id)
                ->where(fn ($q) => ArticleVisibility::apply($q, $user->id, 'article_veille.article_id'))
                ->count(),
        );
    }

    public function test_suppression_regle_justification_en_cascade(): void
    {
        [$user, , $veille] = $this->fixture();
        $r1 = $this->exclusion($user, 'url', 'indeed.com');
        $r2 = $this->exclusion($user, 'keyword', 'emploi');
        $a = $this->article('https://indeed.com/1', 'offre emploi');
        app(ArticleVeilleWriter::class)->record($veille, $a->id, 'kw');
        $this->assertCount(2, DB::table('article_user_hidden')->get());

        $r1->delete(); // cascade : seule sa justification disparaît
        $this->assertSame(1, $this->hiddenCount());
        $this->assertSame($r2->id, DB::table('article_user_hidden')->value('exclusion_id'));
    }

    public function test_article_commun_integre_non_modifie(): void
    {
        [$user, , $veille] = $this->fixture();
        $this->exclusion($user, 'url', 'indeed.com');
        $a = $this->article('https://indeed.com/1');
        $before = Article::findOrFail($a->id)->toArray();
        app(ArticleVeilleWriter::class)->record($veille, $a->id, 'kw');
        $this->assertEquals($before, Article::findOrFail($a->id)->toArray());
    }
}

<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\ArticleVeille;
use App\Models\Source;
use App\Models\User;
use App\Models\UserSource;
use App\Models\Veille;
use App\Models\VeilleAlert;
use App\Models\VeilleKeyword;
use App\Services\ArticleVeilleWriter;
use App\Services\KeywordMatcher;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Génération des digests (M5.2) : curseur `article_veille.id`,
 * plafond 50, verrou des écritures — bi-moteur.
 */
class AlertGenerationTest extends TestCase
{
    use RefreshDatabase;

    private function veille(User $user, bool $alerts = true, bool $active = true): Veille
    {
        return Veille::factory()->create([
            'user_id' => $user->id,
            'is_active' => $active,
            'alerts_enabled' => $alerts,
        ]);
    }

    /** @return Collection<int, ArticleVeille> */
    private function createMatches(Veille $veille, int $count): Collection
    {
        ArticleVeille::factory()->count($count)->create(['veille_id' => $veille->id]);

        // Relire les lignes : l'id auto-incrémenté MariaDB n'est pas
        // reporté sur les instances sauvegardées ($incrementing=false).
        return ArticleVeille::where('veille_id', $veille->id)->orderBy('id')->get();
    }

    private function generate(): int
    {
        return Artisan::call('alerts:generate');
    }

    public function test_premier_digest_couvre_toutes_les_correspondances(): void
    {
        $veille = $this->veille(User::factory()->create());
        $matches = $this->createMatches($veille, 3);

        $this->assertSame(0, $this->generate());

        $alert = VeilleAlert::where('veille_id', $veille->id)->sole();
        $this->assertSame(VeilleAlert::STATUS_PENDING, $alert->status);
        $this->assertSame(0, (int) $alert->start_match_id);
        $this->assertSame($matches->max('id'), (int) $alert->end_match_id);
        $this->assertSame(now('Europe/Paris')->toDateString(), $alert->digest_date->toDateString());
        // Premier digest : origine temporelle = création de la veille.
        $this->assertSame($veille->created_at->format('Y-m-d H:i:s'), $alert->period_start->format('Y-m-d H:i:s'));
    }

    public function test_digest_suivant_reprend_apres_end_match_id(): void
    {
        $veille = $this->veille(User::factory()->create());
        $this->createMatches($veille, 2);
        $this->generate();
        $first = VeilleAlert::where('veille_id', $veille->id)->sole();
        $first->update(['status' => VeilleAlert::STATUS_SENT]);

        $new = $this->createMatches($veille, 2);
        $this->travel(1)->days();
        $this->generate();

        $alerts = VeilleAlert::where('veille_id', $veille->id)->orderBy('id')->get();
        $this->assertCount(2, $alerts);
        $this->assertSame((int) $first->end_match_id, (int) $alerts[1]->start_match_id);
        $this->assertSame($new->max('id'), (int) $alerts[1]->end_match_id);
    }

    public function test_plafond_50_avec_49_50_51_correspondances(): void
    {
        $veille = $this->veille(User::factory()->create());
        $ids = $this->createMatches($veille, 49)->sortBy('id')->values();
        $this->generate();
        $this->assertSame($ids[48]->id, (int) VeilleAlert::where('veille_id', $veille->id)->value('end_match_id'));

        $v50 = $this->veille(User::factory()->create());
        $ids = $this->createMatches($v50, 50)->sortBy('id')->values();
        $this->generate();
        $this->assertSame($ids[49]->id, (int) VeilleAlert::where('veille_id', $v50->id)->value('end_match_id'));

        // 51 correspondances : clôture au 50e id, jamais au watermark.
        $v51 = $this->veille(User::factory()->create());
        $ids = $this->createMatches($v51, 51)->sortBy('id')->values();
        $this->generate();
        $alert = VeilleAlert::where('veille_id', $v51->id)->sole();
        $this->assertSame($ids[49]->id, (int) $alert->end_match_id);
        $this->assertLessThan($ids[50]->id, (int) $alert->end_match_id);
    }

    public function test_73_correspondances_reprise_sur_deux_digests(): void
    {
        $veille = $this->veille(User::factory()->create());
        $ids = $this->createMatches($veille, 73)->sortBy('id')->values();
        $this->generate();

        $first = VeilleAlert::where('veille_id', $veille->id)->sole();
        $this->assertSame($ids[49]->id, (int) $first->end_match_id);
        $first->update(['status' => VeilleAlert::STATUS_SENT]);

        $this->travel(1)->days();
        $this->generate();

        $second = VeilleAlert::where('veille_id', $veille->id)->orderByDesc('id')->first();
        $this->assertSame($ids[49]->id, (int) $second->start_match_id);
        $this->assertSame($ids[72]->id, (int) $second->end_match_id);
    }

    public function test_aucun_digest_sans_correspondance(): void
    {
        $veille = $this->veille(User::factory()->create());
        $this->assertSame(0, $this->generate());
        $this->assertSame(0, VeilleAlert::count());
    }

    public function test_curseur_inchange_sans_nouvelle_correspondance(): void
    {
        $veille = $this->veille(User::factory()->create());
        $this->createMatches($veille, 2);
        $this->generate();
        VeilleAlert::where('veille_id', $veille->id)->sole()
            ->update(['status' => VeilleAlert::STATUS_SENT]);

        $this->travel(1)->days();
        $this->generate();
        $this->assertSame(1, VeilleAlert::where('veille_id', $veille->id)->count());
    }

    public function test_veille_inactive_ou_alertes_desactivees(): void
    {
        $user = User::factory()->create();
        $inactive = $this->veille($user, alerts: true, active: false);
        $disabled = $this->veille($user, alerts: false);
        $this->createMatches($inactive, 2);
        $this->createMatches($disabled, 2);

        $this->generate();
        $this->assertSame(0, VeilleAlert::count());
    }

    public function test_alerte_non_resolue_bloque_nouvelle_generation(): void
    {
        $veille = $this->veille(User::factory()->create());
        $this->createMatches($veille, 2);
        $this->generate();
        $this->createMatches($veille, 2);

        $this->generate();
        $this->assertSame(1, VeilleAlert::where('veille_id', $veille->id)->count());
    }

    public function test_created_at_identiques_sans_perte(): void
    {
        $veille = $this->veille(User::factory()->create());
        $same = '2026-11-02 10:00:00.000000';
        foreach (range(1, 55) as $i) {
            ArticleVeille::factory()->create([
                'veille_id' => $veille->id,
                'created_at' => $same,
            ]);
        }

        $this->generate();
        $first = VeilleAlert::where('veille_id', $veille->id)->sole();
        $inWindow = ArticleVeille::where('veille_id', $veille->id)
            ->where('id', '>', (int) $first->start_match_id)
            ->where('id', '<=', (int) $first->end_match_id)->count();
        $this->assertSame(50, $inWindow);

        $first->update(['status' => VeilleAlert::STATUS_SENT]);
        $this->travel(1)->days();
        $this->generate();
        $second = VeilleAlert::where('veille_id', $veille->id)->orderByDesc('id')->first();
        $inSecond = ArticleVeille::where('veille_id', $veille->id)
            ->where('id', '>', (int) $second->start_match_id)
            ->where('id', '<=', (int) $second->end_match_id)->count();
        $this->assertSame(5, $inSecond);
    }

    public function test_suppression_puis_recreation_nouvel_identifiant(): void
    {
        $user = User::factory()->create();
        $veille = $this->veille($user);
        $match = $this->createMatches($veille, 1)->first();
        $writer = app(ArticleVeilleWriter::class);

        $this->generate();
        $first = VeilleAlert::where('veille_id', $veille->id)->sole();
        $first->update(['status' => VeilleAlert::STATUS_SENT]);

        $this->travel(1)->days();
        $writer->deleteFor($veille, [$match->article_id]);
        $this->assertTrue($writer->record($veille, $match->article_id, 're-créé'));
        $new = ArticleVeille::where('veille_id', $veille->id)->sole();
        $this->assertGreaterThan((int) $match->id, (int) $new->id);

        $this->travel(1)->days();
        $this->generate();
        $second = VeilleAlert::where('veille_id', $veille->id)->orderByDesc('id')->first();
        $this->assertSame((int) $new->id, (int) $second->end_match_id);
    }

    public function test_correspondance_creee_apres_watermark_traitee_ulterieurement(): void
    {
        $veille = $this->veille(User::factory()->create());
        $this->createMatches($veille, 2);
        $this->generate();
        VeilleAlert::where('veille_id', $veille->id)->sole()
            ->update(['status' => VeilleAlert::STATUS_SENT]);

        // La veille a déjà 2 correspondances : la "tardive" est la
        // dernière par id, pas la première de la collection relue.
        $late = $this->createMatches($veille, 1)->last();
        $this->travel(2)->days();
        $this->generate();
        $second = VeilleAlert::where('veille_id', $veille->id)->orderByDesc('id')->first();
        $this->assertSame((int) $late->id, (int) $second->end_match_id);
    }

    public function test_digest_date_est_le_jour_civil_europe_paris(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-04 23:30:00', 'UTC'));
        $veille = $this->veille(User::factory()->create());
        $this->createMatches($veille, 1);

        $this->generate();
        // 23:30 UTC = lendemain 00:30 à Paris (hiver, UTC+1).
        $this->assertSame('2026-11-05', VeilleAlert::sole()->digest_date->toDateString());
        Carbon::setTestNow();
    }

    public function test_isolation_des_fenetres_entre_veilles(): void
    {
        $user = User::factory()->create();
        $v1 = $this->veille($user);
        $v2 = $this->veille($user);
        $this->createMatches($v1, 3);
        $this->createMatches($v2, 1);

        $this->generate();
        $a1 = VeilleAlert::where('veille_id', $v1->id)->sole();
        $a2 = VeilleAlert::where('veille_id', $v2->id)->sole();
        $this->assertSame(
            ArticleVeille::where('veille_id', $v1->id)->max('id'),
            (int) $a1->end_match_id
        );
        $this->assertSame(
            ArticleVeille::where('veille_id', $v2->id)->max('id'),
            (int) $a2->end_match_id
        );
    }

    public function test_les_ecritures_du_matcher_creent_la_correspondance(): void
    {
        // Chemin applicatif réel : matchArticle → ArticleVeilleWriter
        // → ligne créée (le verrou détenu par le writer est prouvé
        // dans AlertConcurrencyTest::test_matcher_attend_le_verrou).
        $user = User::factory()->create();
        $source = Source::factory()->create();
        UserSource::factory()->for($user)->for($source)->create();
        $veille = Veille::factory()->for($user)->create(['is_active' => true]);
        $veille->sources()->attach($source->id);
        VeilleKeyword::factory()->for($veille)->create(['term' => 'veille']);
        $article = Article::factory()->for($source)->create(['title' => 'Revue de veille hebdo']);

        app(KeywordMatcher::class)->matchArticle($article);

        $this->assertSame(1, ArticleVeille::where('veille_id', $veille->id)
            ->where('article_id', $article->id)->count());
    }
}

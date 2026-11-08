<?php

namespace Tests\Feature;

use App\Jobs\SendVeilleDigestJob;
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
use App\Services\VeilleRematcher;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Verrou `veilles` FOR UPDATE (M5.2) — garanties de concurrence.
 * Nécessite MariaDB `veille_test` et une seconde connexion réelle :
 * ignoré sous SQLite (le verrouillage InnoDB ne s'y démontre pas).
 * Pas de RefreshDatabase : les données doivent être COMMITÉES pour
 * être visibles de la connexion auxiliaire ; nettoyage en tearDown.
 */
class AlertConcurrencyTest extends TestCase
{
    private ?User $user = null;

    /** @var list<int> */
    private array $veilleIds = [];

    /** Repères d'ids relevés en setUp : tout ce qui est créé au-delà est nettoyé. */
    private int $maxArticleId = 0;

    private int $maxSourceId = 0;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mariadb') {
            $this->markTestSkipped('Verrouillage InnoDB : test réservé à MariaDB (phpunit.mariadb.xml).');
        }
        $this->assertSame('veille_test', DB::connection()->getDatabaseName());

        Artisan::call('migrate', ['--force' => true]);
        config(['database.connections.mariadb_aux' => config('database.connections.mariadb')]);
        DB::statement('SET innodb_lock_wait_timeout = 1');
        $this->aux()->statement('SET innodb_lock_wait_timeout = 1');

        $this->maxArticleId = (int) DB::table('articles')->max('id');
        $this->maxSourceId = (int) DB::table('sources')->max('id');
        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        // Toute transaction auxiliaire encore ouverte doit être
        // relâchée AVANT le nettoyage : ses verrous bloqueraient les
        // suppressions et le migrate:fresh des tests suivants.
        try {
            $aux = DB::connection('mariadb_aux');
            while ($aux->transactionLevel() > 0) {
                $aux->rollBack(1);
            }
        } catch (\Throwable) {
        }

        foreach ($this->veilleIds as $id) {
            DB::table('article_veille')->where('veille_id', $id)->delete();
            DB::table('veille_alerts')->where('veille_id', $id)->delete();
            DB::table('veilles')->where('id', $id)->delete();
        }
        $this->user?->delete();
        // Articles et sources factory commités hors transaction : sans
        // ce nettoyage ils persistaient dans veille_test et faussaient
        // les tests suivants de la suite MariaDB complète.
        if (DB::connection()->getDriverName() === 'mariadb') {
            DB::table('articles')->where('id', '>', $this->maxArticleId)->delete();
            DB::table('sources')->where('id', '>', $this->maxSourceId)->delete();
        }
        DB::purge('mariadb_aux');
        parent::tearDown();
    }

    /** Connexion auxiliaire réelle (second PDO → concurrence vraie). */
    private function aux(): Connection
    {
        return DB::connection('mariadb_aux');
    }

    private function veille(): Veille
    {
        $veille = Veille::factory()->create([
            'user_id' => $this->user->id,
            'is_active' => true,
            'alerts_enabled' => true,
        ]);
        $this->veilleIds[] = $veille->id;

        return $veille;
    }

    /** Insertion de correspondance « protocole » : verrou puis insert. */
    private function auxInsertMatch(Veille $veille): int
    {
        $this->aux()->table('veilles')->where('id', $veille->id)->lockForUpdate()->value('id');
        $article = Article::factory()->create();
        $this->aux()->table('article_veille')->insert([
            'veille_id' => $veille->id,
            'article_id' => $article->id,
            'user_id' => $veille->user_id,
            'matched_keyword' => 'concurrent',
            'created_at' => now('UTC')->format('Y-m-d H:i:s.u'),
        ]);

        return (int) $this->aux()->table('article_veille')
            ->where('veille_id', $veille->id)->max('id');
    }

    public function test_generation_bloquee_pendant_le_verrou_puis_voit_la_ligne(): void
    {
        $veille = $this->veille();
        ArticleVeille::factory()->create(['veille_id' => $veille->id]);

        // Écrivain sous verrou, insertion non commitée.
        try {
            $this->aux()->beginTransaction();
            $matchId = $this->auxInsertMatch($veille);

            // Le générateur attend le verrou → timeout (preuve).
            // Le générateur attend le verrou → timeout (preuve).
            try {
                Artisan::call('alerts:generate');
                $blocked = false;
            } catch (\Throwable) {
                $blocked = true; // Blocage attendu : timeout InnoDB.
            }
            $this->assertTrue($blocked);
            $this->assertSame(0, VeilleAlert::count());
        } finally {
            $this->aux()->commit();
        }
        $this->assertSame(0, Artisan::call('alerts:generate'));

        $alert = VeilleAlert::sole();
        $this->assertSame($matchId, (int) $alert->end_match_id);
        $this->assertSame(2, ArticleVeille::where('veille_id', $veille->id)
            ->where('id', '<=', $alert->end_match_id)->count());
    }

    public function test_ecrivain_bloque_pendant_le_verrou_du_generateur(): void
    {
        $veille = $this->veille();
        $article = Article::factory()->create();

        // Le « générateur » détient le verrou de la veille.
        $this->aux()->beginTransaction();
        $this->aux()->table('veilles')->where('id', $veille->id)->lockForUpdate()->value('id');

        // ArticleVeilleWriter doit attendre → timeout de verrou.
        $this->expectException(\PDOException::class);
        try {
            app(ArticleVeilleWriter::class)->record($veille, $article->id, 'kw');
        } finally {
            $this->aux()->commit();
        }
    }

    public function test_rollback_libere_verrou_et_abandonne_la_ligne(): void
    {
        $veille = $this->veille();

        $this->aux()->beginTransaction();
        try {
            $this->auxInsertMatch($veille);
        } finally {
            $this->aux()->rollBack();
        }

        $this->assertSame(0, ArticleVeille::where('veille_id', $veille->id)->count());

        // Verrou relâché : le générateur s'exécute sans attente.
        $this->assertSame(0, Artisan::call('alerts:generate'));
        $this->assertSame(0, VeilleAlert::count());
    }

    public function test_deux_generations_serialisees_un_seul_digest(): void
    {
        $veille = $this->veille();
        ArticleVeille::factory()->create(['veille_id' => $veille->id]);

        // Première « génération » concurrente : verrou maintenu.
        try {
            $this->aux()->beginTransaction();
            $this->aux()->table('veilles')->where('id', $veille->id)->lockForUpdate()->value('id');
            try {
                Artisan::call('alerts:generate');
                $blocked = false;
            } catch (\Throwable) {
                $blocked = true;
            }
            $this->assertTrue($blocked);
        } finally {
            $this->aux()->commit();
        }

        $this->assertSame(0, Artisan::call('alerts:generate'));
        $this->assertSame(1, VeilleAlert::count());

        // Relance immédiate : la garde pending empêche tout doublon.
        $this->assertSame(0, Artisan::call('alerts:generate'));
        $this->assertSame(1, VeilleAlert::count());
    }

    public function test_rematcher_attend_le_verrou_detenu_par_le_generate(): void
    {
        $veille = $this->veille();
        ArticleVeille::factory()->create(['veille_id' => $veille->id]);

        $this->aux()->beginTransaction();
        $this->aux()->table('veilles')->where('id', $veille->id)->lockForUpdate()->value('id');

        // Le rematcher écrit via le writer → doit attendre → timeout.
        $this->expectException(\PDOException::class);
        try {
            app(VeilleRematcher::class)->rematch($veille);
        } finally {
            $this->aux()->commit();
        }
    }

    public function test_matcher_attend_le_verrou_detenu_par_le_generate(): void
    {
        // Preuve que matchArticle écrit via ArticleVeilleWriter :
        // l'insertion de la correspondance exige le verrou veilles.
        $source = Source::factory()->create();
        UserSource::factory()->for($this->user)->for($source)->create();
        $veille = $this->veille();
        $veille->sources()->attach($source->id);
        VeilleKeyword::factory()->for($veille)->create(['term' => 'veille']);
        $article = Article::factory()->for($source)->create(['title' => 'Revue de veille']);

        $this->aux()->beginTransaction();
        $this->aux()->table('veilles')->where('id', $veille->id)->lockForUpdate()->value('id');

        $this->expectException(\PDOException::class);
        try {
            app(KeywordMatcher::class)->matchArticle($article);
        } finally {
            $this->aux()->commit();
        }
    }

    public function test_reservation_atomique_refusee_sous_verrou_concurrent(): void
    {
        // Un worker détient une réservation non commitée (ligne
        // verrouillée par son UPDATE) : le job concurrent est bloqué
        // puis, après commit, voit le bail valide et avorte.
        $veille = $this->veille();
        $alert = VeilleAlert::factory()->create([
            'veille_id' => $veille->id, 'user_id' => $this->user->id,
        ]);

        try {
            $this->aux()->beginTransaction();
            $this->aux()->table('veille_alerts')->where('id', $alert->id)
                ->update(['status' => 'processing', 'reserved_at' => now('UTC'),
                    'reservation_token' => 'worker-aux', 'attempts' => 1]);

            $blocked = false;
            try {
                (new SendVeilleDigestJob($alert->id))->handle();
            } catch (\Throwable) {
                $blocked = true; // UPDATE de réservation bloqué.
            }
        } finally {
            $this->aux()->commit();
        }

        // Après commit : bail valide 'worker-aux' → le job refuse.
        (new SendVeilleDigestJob($alert->id))->handle();
        $alert->refresh();
        $this->assertSame('worker-aux', $alert->reservation_token);
        $this->assertSame(1, $alert->attempts);
    }

    public function test_ecritures_sur_plusieurs_veilles(): void
    {
        $v1 = $this->veille();
        $v2 = $this->veille();
        $writer = app(ArticleVeilleWriter::class);
        $a1 = Article::factory()->create();
        $a2 = Article::factory()->create();

        $this->assertTrue($writer->record($v1, $a1->id, 'x'));
        $this->assertTrue($writer->record($v2, $a2->id, 'y'));
        $this->assertFalse($writer->record($v1, $a1->id, 'x'));

        $this->assertSame(0, Artisan::call('alerts:generate'));
        $this->assertSame(2, VeilleAlert::count());
    }
}

<?php

namespace Tests\Feature;

use App\Jobs\SendVeilleDigestJob;
use App\Mail\VeilleDigestMail;
use App\Models\Article;
use App\Models\ArticleVeille;
use App\Models\User;
use App\Models\Veille;
use App\Models\VeilleAlert;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Outbox des digests (M5.3) : réservation atomique, token, bail
 * 10 min, 3 tentatives, reprise — bi-moteur, Mail::fake.
 */
class AlertOutboxTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Veille $veille;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->user = User::factory()->create();
        $this->veille = Veille::factory()->create([
            'user_id' => $this->user->id, 'alerts_enabled' => true,
        ]);
    }

    private int $alertSeq = 0;

    private function alert(array $overrides = []): VeilleAlert
    {
        // UNIQUE(veille_id, digest_date) : chaque alerte de test du
        // même jour est décalée d'un jour civil.
        return VeilleAlert::factory()->create(array_merge([
            'veille_id' => $this->veille->id,
            'user_id' => $this->user->id,
            'digest_date' => now('Europe/Paris')->subDays($this->alertSeq++)->toDateString(),
        ], $overrides));
    }

    private function lastMatchId(int $count): int
    {
        ArticleVeille::factory()->count($count)->create(['veille_id' => $this->veille->id]);

        return (int) ArticleVeille::where('veille_id', $this->veille->id)->max('id');
    }

    private function runJob(int $alertId): void
    {
        (new SendVeilleDigestJob($alertId))->handle();
    }

    public function test_reservation_atomique_passe_en_processing(): void
    {
        $end = $this->lastMatchId(1);
        $alert = $this->alert(['end_match_id' => $end]);

        $this->runJob($alert->id);

        $alert->refresh();
        $this->assertSame(VeilleAlert::STATUS_SENT, $alert->status);
        $this->assertSame(1, $alert->attempts);
        Mail::assertSent(VeilleDigestMail::class);
    }

    public function test_bail_valide_refuse_seconde_reservation(): void
    {
        $alert = $this->alert([
            'status' => VeilleAlert::STATUS_PROCESSING,
            'reserved_at' => now('UTC'),
            'reservation_token' => 'autre-worker',
            'attempts' => 1,
        ]);

        $this->runJob($alert->id);

        $alert->refresh();
        $this->assertSame(VeilleAlert::STATUS_PROCESSING, $alert->status);
        $this->assertSame('autre-worker', $alert->reservation_token);
        $this->assertSame(1, $alert->attempts);
        Mail::assertNothingSent();
    }

    public function test_sent_et_failed_ne_sont_pas_repris(): void
    {
        foreach ([VeilleAlert::STATUS_SENT, VeilleAlert::STATUS_FAILED] as $status) {
            $alert = $this->alert(['status' => $status]);
            $this->runJob($alert->id);
            $this->assertSame($status, $alert->refresh()->status);
        }
        Mail::assertNothingSent();
    }

    public function test_succes_enregistre_sent_at_et_articles_count(): void
    {
        $end = $this->lastMatchId(3);
        $alert = $this->alert(['end_match_id' => $end]);

        $this->runJob($alert->id);

        $alert->refresh();
        $this->assertSame(VeilleAlert::STATUS_SENT, $alert->status);
        $this->assertNotNull($alert->sent_at);
        $this->assertSame(3, $alert->articles_count);
        $this->assertNull($alert->reservation_token);
        Mail::assertSent(VeilleDigestMail::class,
            fn (VeilleDigestMail $m) => $m->hasTo($this->user->email) && $m->items->count() === 3);
    }

    public function test_echec_retourne_pending(): void
    {
        $end = $this->lastMatchId(1);
        $alert = $this->alert(['end_match_id' => $end]);
        $this->throwMailer();

        $this->runJob($alert->id);

        $alert->refresh();
        $this->assertSame(VeilleAlert::STATUS_PENDING, $alert->status);
        $this->assertSame(1, $alert->attempts);
        $this->assertNotNull($alert->error);
        $this->assertNull($alert->reservation_token);
    }

    public function test_troisieme_echec_passe_failed(): void
    {
        $end = $this->lastMatchId(1);
        $alert = $this->alert(['end_match_id' => $end, 'attempts' => 2]);
        $this->throwMailer();

        $this->runJob($alert->id);

        $alert->refresh();
        $this->assertSame(VeilleAlert::STATUS_FAILED, $alert->status);
        $this->assertSame(3, $alert->attempts);
    }

    public function test_bail_expire_est_repris(): void
    {
        $end = $this->lastMatchId(1);
        $alert = $this->alert([
            'end_match_id' => $end,
            'status' => VeilleAlert::STATUS_PROCESSING,
            'reserved_at' => now('UTC')->subMinutes(11),
            'reservation_token' => 'vieux-worker',
            'attempts' => 1,
        ]);

        $this->runJob($alert->id);

        $alert->refresh();
        $this->assertSame(VeilleAlert::STATUS_SENT, $alert->status);
        $this->assertSame(2, $alert->attempts);
        Mail::assertSent(VeilleDigestMail::class);
    }

    public function test_deux_jobs_sequentiels_un_seul_envoi(): void
    {
        $end = $this->lastMatchId(1);
        $alert = $this->alert(['end_match_id' => $end]);

        $this->runJob($alert->id);
        $this->runJob($alert->id); // sent → no-op

        Mail::assertSent(VeilleDigestMail::class, 1);
    }

    public function test_ancien_token_ne_cloture_pas_une_reservation_recente(): void
    {
        $end = $this->lastMatchId(1);
        $alert = $this->alert([
            'end_match_id' => $end,
            'status' => VeilleAlert::STATUS_PROCESSING,
            'reserved_at' => now('UTC')->subMinutes(11),
            'reservation_token' => 'ancien',
            'attempts' => 1,
        ]);

        // Un autre worker reprend le bail entre-temps.
        DB::table('veille_alerts')->where('id', $alert->id)->update([
            'reservation_token' => 'nouveau', 'reserved_at' => now('UTC'),
        ]);

        // La clôture gardée par 'ancien' n'affecte rien.
        $affected = DB::table('veille_alerts')->where('id', $alert->id)
            ->where('status', VeilleAlert::STATUS_PROCESSING)
            ->where('reservation_token', 'ancien')
            ->update(['status' => VeilleAlert::STATUS_SENT]);
        $this->assertSame(0, $affected);
        $this->assertSame('nouveau', $alert->refresh()->reservation_token);
    }

    public function test_aucun_email_quand_toutes_correspondances_disparues(): void
    {
        $end = $this->lastMatchId(1);
        $alert = $this->alert(['end_match_id' => $end]);
        ArticleVeille::where('veille_id', $this->veille->id)->delete();

        $this->runJob($alert->id);

        $alert->refresh();
        $this->assertSame(VeilleAlert::STATUS_SENT, $alert->status);
        $this->assertSame(0, $alert->articles_count);
        Mail::assertNothingSent();
    }

    public function test_association_supprimee_absente_du_digest(): void
    {
        $m1 = ArticleVeille::factory()->create(['veille_id' => $this->veille->id]);
        ArticleVeille::factory()->create(['veille_id' => $this->veille->id]);
        $end = (int) ArticleVeille::where('veille_id', $this->veille->id)->max('id');
        $alert = $this->alert(['end_match_id' => $end]);
        ArticleVeille::where('veille_id', $m1->veille_id)
            ->where('article_id', $m1->article_id)->delete();

        $this->runJob($alert->id);

        $alert->refresh();
        $this->assertSame(1, $alert->articles_count);
        Mail::assertSent(VeilleDigestMail::class, fn (VeilleDigestMail $m) => $m->items->count() === 1);
    }

    public function test_plafond_50_articles(): void
    {
        $end = $this->lastMatchId(55);
        $alert = $this->alert(['end_match_id' => $end]);

        $this->runJob($alert->id);

        $this->assertSame(50, $alert->refresh()->articles_count);
    }

    public function test_donnees_rss_echappees_dans_le_mail(): void
    {
        $article = Article::factory()->create(['title' => '<script>alert(1)</script> Titre']);
        $match = ArticleVeille::factory()->create([
            'veille_id' => $this->veille->id, 'article_id' => $article->id,
        ]);
        $alert = $this->alert(['end_match_id' => $match->id]);

        $mail = new VeilleDigestMail($alert->load('veille'), collect([$match->load('article.source')]));
        $html = $mail->render();

        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>alert(1)', $html);
    }

    public function test_dates_affichees_en_europe_paris(): void
    {
        $article = Article::factory()->create(['published_at' => '2026-11-03 22:00:00']);
        $match = ArticleVeille::factory()->create([
            'veille_id' => $this->veille->id, 'article_id' => $article->id,
        ]);
        $alert = $this->alert([
            'end_match_id' => $match->id,
            'period_start' => '2026-11-03 22:30:00',
            'period_end' => '2026-11-04 06:30:00',
        ]);

        $html = (new VeilleDigestMail($alert->load('veille'), collect([$match->load('article.source')])))->render();

        // 22:30 UTC = 23:30 Paris (hiver) ; 06:30 UTC = 07:30 Paris.
        $this->assertStringContainsString('03/11/2026 à 23:30', $html);
        $this->assertStringContainsString('04/11/2026 à 07:30', $html);
        $this->assertStringContainsString('03/11/2026 à 23:00', $html);
    }

    public function test_distribute_met_en_file_sans_envoyer(): void
    {
        Queue::fake();
        $this->alert();
        $this->alert(['status' => VeilleAlert::STATUS_SENT]);

        Artisan::call('alerts:distribute');

        Queue::assertPushed(SendVeilleDigestJob::class, 1);
        Mail::assertNothingSent();
    }

    public function test_distribute_ignore_les_tentatives_epuisees(): void
    {
        Queue::fake();
        $this->alert();
        $this->alert(['attempts' => SendVeilleDigestJob::MAX_ATTEMPTS]);

        Artisan::call('alerts:distribute');

        Queue::assertPushed(SendVeilleDigestJob::class, 1);
    }

    public function test_job_sur_alerte_supprimee_ne_fait_rien(): void
    {
        $alert = $this->alert();
        $alert->delete();

        (new SendVeilleDigestJob($alert->id))->handle();

        Mail::assertNothingSent();
        $this->assertSame(0, VeilleAlert::count());
    }

    public function test_sweep_redispatche_pending_et_expire_fail_epuisees(): void
    {
        Queue::fake();
        $pending = $this->alert();
        $expired = $this->alert([
            'status' => VeilleAlert::STATUS_PROCESSING,
            'reserved_at' => now('UTC')->subMinutes(11),
            'attempts' => 1,
        ]);
        $exhausted = $this->alert([
            'status' => VeilleAlert::STATUS_PROCESSING,
            'reserved_at' => now('UTC')->subMinutes(11),
            'attempts' => 3,
        ]);
        $inflight3rd = $this->alert([
            'status' => VeilleAlert::STATUS_PROCESSING,
            'reserved_at' => now('UTC'),
            'attempts' => 3,
        ]);

        Artisan::call('alerts:sweep');

        // pending + bail expiré redispatchés ; pas l'exhausted ni la 3ᵉ en vol.
        Queue::assertPushed(SendVeilleDigestJob::class, 2);
        $this->assertSame(VeilleAlert::STATUS_FAILED, $exhausted->refresh()->status);
        $this->assertSame(VeilleAlert::STATUS_PROCESSING, $inflight3rd->refresh()->status);
        $this->assertSame(VeilleAlert::STATUS_PENDING, $pending->refresh()->status);
    }

    public function test_sweep_ne_reprend_pas_bail_valide(): void
    {
        Queue::fake();
        $this->alert([
            'status' => VeilleAlert::STATUS_PROCESSING,
            'reserved_at' => now('UTC'),
            'attempts' => 1,
        ]);

        Artisan::call('alerts:sweep');

        Queue::assertNothingPushed();
    }

    /** Force une erreur au moment de l'envoi du mail. */
    private function throwMailer(): void
    {
        // Mail::swap remplace l'instance résolue (MailFake inclus) —
        // contrairement à app->instance('mailer'), déjà consommée.
        $mailer = \Mockery::mock(Mailer::class);
        $mailer->shouldReceive('send')->andThrow(new \RuntimeException('smtp indisponible'));
        $mailer->shouldReceive('to')->andReturnSelf();
        Mail::swap($mailer);
    }
}

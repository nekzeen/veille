<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Source;
use App\Models\User;
use App\Services\CatalogueActivityService;
use App\Services\SourceSubscriptionService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * M13.3c — concurrence RÉELLE retrait / abonnement : deux connexions
 * MariaDB distinctes vers `veille_test`, le code de production
 * s'exécutant des deux côtés (bascule de la connexion par défaut).
 *
 * PHP étant mono-thread, l'attente bloquante est démontrée par
 * l'expiration du verrou (innodb_lock_wait_timeout = 1 → erreur 1205)
 * pendant que l'autre connexion détient sa transaction ouverte ; la
 * seconde opération est ensuite rejouée après le commit de la première.
 *
 * Pas de RefreshDatabase : les données doivent être COMMITÉES pour être
 * visibles de l'autre connexion ; nettoyage explicite en tearDown.
 * Ignoré sous SQLite (pas de verrou de ligne).
 */
class CatalogueConcurrencyTest extends TestCase
{
    private const MAIN = 'mariadb';

    private const AUX = 'mariadb_cat_aux';

    /** Vrai seulement si le test tourne réellement sur MariaDB veille_test. */
    private bool $active = false;

    /** @var list<int> */
    private array $userIds = [];

    /** @var list<int> */
    private array $sourceIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mariadb') {
            $this->markTestSkipped('Verrouillage InnoDB : test réservé à MariaDB (phpunit.mariadb.xml).');
        }
        $this->assertSame(self::MAIN, DB::getDefaultConnection());
        $this->assertSame('veille_test', DB::connection()->getDatabaseName());

        Artisan::call('migrate', ['--force' => true]);
        config(['database.connections.'.self::AUX => config('database.connections.'.self::MAIN)]);
        $this->assertSame('veille_test', DB::connection(self::AUX)->getDatabaseName());
        DB::connection(self::MAIN)->statement('SET innodb_lock_wait_timeout = 1');
        DB::connection(self::AUX)->statement('SET innodb_lock_wait_timeout = 1');
        $this->active = true;
    }

    protected function tearDown(): void
    {
        if ($this->active) {
            foreach ([self::AUX, self::MAIN] as $c) {
                try {
                    while (DB::connection($c)->transactionLevel() > 0) {
                        DB::connection($c)->rollBack(0);
                    }
                } catch (\Throwable) {
                }
            }
            DB::setDefaultConnection(self::MAIN);
            DB::table('user_sources')->whereIn('source_id', $this->sourceIds)->delete();
            DB::table('sources')->whereIn('id', $this->sourceIds)->delete();
            DB::table('users')->whereIn('id', $this->userIds)->delete();
            DB::purge(self::AUX);
        }
        parent::tearDown();
    }

    /** Exécute $fn avec la connexion $conn comme connexion par défaut. */
    private function on(string $conn, callable $fn): mixed
    {
        DB::setDefaultConnection($conn);
        try {
            return $fn();
        } finally {
            DB::setDefaultConnection(self::MAIN);
        }
    }

    private function fixtures(): array
    {
        $user = User::factory()->create();
        $source = Source::factory()->catalogue()->create([
            'catalogue_inactive_at' => now('UTC')->subDays(120),
        ]);
        $this->userIds[] = $user->id;
        $this->sourceIds[] = $source->id;

        return [$user, $source];
    }

    private static function isLockTimeout(\Throwable $e): bool
    {
        return $e instanceof QueryException && (int) ($e->errorInfo[1] ?? 0) === 1205;
    }

    public function test_retrait_verrouille_en_premier_abonnement_refuse(): void
    {
        [$user, $source] = $this->fixtures();
        $activity = app(CatalogueActivityService::class);
        $subs = app(SourceSubscriptionService::class);

        // MAIN : retrait réel, transaction laissée ouverte (verrou détenu).
        DB::connection(self::MAIN)->beginTransaction();
        $this->assertTrue($activity->withdrawIfStillInactive($source->id));

        // AUX : abonnement réel — bloqué par le verrou, expire (1205).
        $blocked = null;
        try {
            $this->on(self::AUX, fn () => $subs->subscribe($user, Source::find($source->id)));
        } catch (\Throwable $e) {
            $blocked = $e;
        }
        $this->assertNotNull($blocked, 'l\'abonnement aurait dû attendre le verrou');
        $this->assertTrue(self::isLockTimeout($blocked), 'attendu : lock wait timeout, reçu : '.$blocked->getMessage());

        DB::connection(self::MAIN)->commit();

        // AUX rejoue après commit : lit la source privée → refus.
        $refused = false;
        try {
            $this->on(self::AUX, fn () => $subs->subscribe($user, Source::find($source->id)));
        } catch (\RuntimeException $e) {
            $refused = ! self::isLockTimeout($e) && $e->getMessage() === 'Source non accessible.';
        }
        $this->assertTrue($refused);

        $row = DB::connection(self::AUX)->table('sources')->where('id', $source->id)->first();
        $this->assertSame(0, (int) $row->is_catalogue);
        $this->assertNotNull($row->catalogue_inactive_at);
        $this->assertSame(0, DB::connection(self::AUX)->table('user_sources')->where('source_id', $source->id)->count());
    }

    /**
     * Intercalation TOCTOU côté abonnement : le retrait (AUX) est lancé
     * juste APRÈS la lecture de la source par l'abonnement (MAIN), avant
     * l'insertion du lien. Sans le FOR UPDATE de l'abonnement, le retrait
     * passerait et l'abonnement serait créé sur une source devenue privée.
     */
    public function test_toctou_retrait_entre_verification_et_ecriture_abonnement(): void
    {
        [$user, $source] = $this->fixtures();
        $activity = app(CatalogueActivityService::class);
        $subs = app(SourceSubscriptionService::class);

        $triggered = false;
        $blocked = null;
        DB::listen(function ($q) use (&$triggered, &$blocked, $activity, $source): void {
            if ($triggered || $q->connectionName !== self::MAIN || ! str_contains($q->sql, 'from `sources`')) {
                return;
            }
            $triggered = true;
            try {
                $this->on(self::AUX, fn () => $activity->withdrawIfStillInactive($source->id));
            } catch (\Throwable $e) {
                $blocked = $e;
            }
        });

        $subs->subscribe($user, $source); // MAIN, auto-commit en fin de transaction

        $this->assertTrue($triggered);
        $this->assertNotNull($blocked, 'le retrait aurait dû attendre le verrou de l\'abonnement');
        $this->assertTrue(self::isLockTimeout($blocked), $blocked->getMessage());
        $this->assertFalse($this->on(self::AUX, fn () => $activity->withdrawIfStillInactive($source->id)));

        $this->assertSame(1, (int) DB::connection(self::AUX)->table('sources')->where('id', $source->id)->value('is_catalogue'));
        $this->assertSame(1, DB::connection(self::AUX)->table('user_sources')->where('source_id', $source->id)->count());
    }

    /**
     * Intercalation TOCTOU côté retrait : l'abonnement (AUX) est lancé
     * juste APRÈS la vérification des liens par le retrait (MAIN), avant
     * l'UPDATE. Sans le FOR UPDATE du retrait, l'abonnement serait commité
     * puis la source retirée malgré son nouvel abonné.
     */
    public function test_toctou_abonnement_entre_verification_et_ecriture_retrait(): void
    {
        [$user, $source] = $this->fixtures();
        $activity = app(CatalogueActivityService::class);
        $subs = app(SourceSubscriptionService::class);

        $triggered = false;
        $blocked = null;
        DB::listen(function ($q) use (&$triggered, &$blocked, $subs, $user, $source): void {
            if ($triggered || $q->connectionName !== self::MAIN || ! str_contains($q->sql, '`source_veille`')) {
                return;
            }
            $triggered = true;
            try {
                $this->on(self::AUX, fn () => $subs->subscribe($user, Source::find($source->id)));
            } catch (\Throwable $e) {
                $blocked = $e;
            }
        });

        $this->assertTrue($activity->withdrawIfStillInactive($source->id)); // MAIN

        $this->assertTrue($triggered);
        $this->assertNotNull($blocked, 'l\'abonnement aurait dû attendre le verrou du retrait');
        $this->assertTrue(self::isLockTimeout($blocked), $blocked->getMessage());
        $this->expectException(\RuntimeException::class);
        try {
            $this->on(self::AUX, fn () => $subs->subscribe($user, Source::find($source->id)));
        } finally {
            $this->assertSame(0, (int) DB::connection(self::AUX)->table('sources')->where('id', $source->id)->value('is_catalogue'));
            $this->assertSame(0, DB::connection(self::AUX)->table('user_sources')->where('source_id', $source->id)->count());
        }
    }

    public function test_abonnement_verrouille_en_premier_source_conservee(): void
    {
        [$user, $source] = $this->fixtures();
        $activity = app(CatalogueActivityService::class);
        $subs = app(SourceSubscriptionService::class);

        // MAIN : abonnement réel, transaction laissée ouverte.
        DB::connection(self::MAIN)->beginTransaction();
        $subs->subscribe($user, $source);

        // AUX : retrait réel — bloqué par le verrou, expire (1205).
        $blocked = null;
        try {
            $this->on(self::AUX, fn () => $activity->withdrawIfStillInactive($source->id));
        } catch (\Throwable $e) {
            $blocked = $e;
        }
        $this->assertNotNull($blocked, 'le retrait aurait dû attendre le verrou');
        $this->assertTrue(self::isLockTimeout($blocked), 'attendu : lock wait timeout, reçu : '.$blocked->getMessage());

        DB::connection(self::MAIN)->commit();

        // AUX rejoue après commit : lien relu sous verrou → conservée.
        $this->assertFalse($this->on(self::AUX, fn () => $activity->withdrawIfStillInactive($source->id)));

        $row = DB::connection(self::AUX)->table('sources')->where('id', $source->id)->first();
        $this->assertSame(1, (int) $row->is_catalogue);
        $this->assertNull($row->catalogue_inactive_at);
        $this->assertSame(1, DB::connection(self::AUX)->table('user_sources')
            ->where('source_id', $source->id)->where('user_id', $user->id)->count());
    }
}

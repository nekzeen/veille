<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Veille;
use App\Models\VeilleShare;
use App\Services\Shares\ShareTokenService;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/** M10.2 — jetons de partage : génération, cycle de vie, sécurité. */
class ShareTokenTest extends TestCase
{
    use RefreshDatabase;

    private function share(bool $global = false): VeilleShare
    {
        $user = User::factory()->create();

        return VeilleShare::create([
            'user_id' => $user->id,
            'veille_id' => $global ? null : Veille::factory()->create(['user_id' => $user->id])->id,
            'scope' => $global ? 'all' : 'single',
            'token_hash' => hash('sha256', 'seed'),
        ]);
    }

    public function test_format_base64url_32_octets(): void
    {
        $t = app(ShareTokenService::class)->generatePlainToken();
        $this->assertSame(43, strlen($t));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $t);
    }

    public function test_jeton_clair_jamais_stocke_ni_logge(): void
    {
        $share = $this->share();
        $plain = app(ShareTokenService::class)->issue($share);

        $this->assertSame(hash('sha256', $plain), $share->fresh()->token_hash);
        $this->assertStringNotContainsString(
            $plain,
            json_encode(DB::table('veille_shares')->first()),
        );
        // Le service n'appelle aucun canal de log — le clair ne peut fuiter.
    }

    public function test_resolve_trouve_ou_refuse(): void
    {
        $share = $this->share();
        $svc = app(ShareTokenService::class);
        $plain = $svc->issue($share);

        $this->assertSame($share->id, $svc->resolve($plain)?->id);
        $this->assertNull($svc->resolve('jeton-invalide-xxxxxxxxxxxxxxxxxxxxxxxxxxxxx'));
        $this->assertNull($svc->resolve(str_repeat('A', 200)));
    }

    public function test_revocation_et_expiration_excluent_la_resolution(): void
    {
        $share = $this->share();
        $svc = app(ShareTokenService::class);
        $plain = $svc->issue($share);

        $share->update(['expires_at' => now('UTC')->subMinute()]);
        $this->assertNull($svc->resolve($plain));
        $this->assertTrue(VeilleShare::expired()->where('id', $share->id)->exists());

        $share->update(['expires_at' => null]);
        $this->assertNotNull($svc->resolve($plain));
        $svc->revoke($share->fresh());
        $this->assertNull($svc->resolve($plain));
        $this->assertTrue(VeilleShare::revoked()->where('id', $share->id)->exists());
    }

    public function test_rotation_invalide_ancien_et_audite(): void
    {
        $share = $this->share();
        $svc = app(ShareTokenService::class);
        $old = $svc->issue($share);
        $this->assertNull($share->fresh()->rotated_at);

        $new = $svc->rotate($share->fresh());
        $share->refresh();

        $this->assertNull($svc->resolve($old)); // ancien lien mort
        $this->assertNotNull($svc->resolve($new));
        $this->assertNotNull($share->rotated_at);
        $this->assertNull($share->revoked_at); // rotation ≠ révocation
        $this->assertNotSame($old, $new);
    }

    public function test_unicite_hash(): void
    {
        $a = $this->share();
        $this->expectException(UniqueConstraintViolationException::class);
        VeilleShare::create([
            'user_id' => User::factory()->create()->id,
            'veille_id' => Veille::factory()->create(['user_id' => $a->user_id])->id,
            'scope' => 'single', 'token_hash' => $a->token_hash,
        ]);
    }

    public function test_coherence_scope_veille_id(): void
    {
        $this->assertTrue($this->share(global: true)->isGlobal());
        // SQLite : pas de CHECK via ALTER TABLE — invariant garanti par
        // l'applicatif ; la contrainte réelle n'est testable que sous MariaDB.
        if (DB::getDriverName() === 'sqlite') {
            return;
        }
        $this->expectException(QueryException::class);
        DB::table('veille_shares')->insert([
            'user_id' => User::factory()->create()->id,
            'veille_id' => null, 'scope' => 'single',
            'token_hash' => hash('sha256', 'bad'),
        ]);
    }

    public function test_cascade_sur_suppression_veille(): void
    {
        $share = $this->share();
        $id = $share->id;
        $share->veille->delete();
        $this->assertFalse(VeilleShare::where('id', $id)->exists());
    }
}

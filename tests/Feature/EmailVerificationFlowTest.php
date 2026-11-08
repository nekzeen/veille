<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/** M12.20 — diagnostic du 403 sur /verify-email/{id}/{hash}. */
class EmailVerificationFlowTest extends TestCase
{
    use RefreshDatabase;

    private function lien(User $u, ?int $expires = null): string
    {
        return URL::temporarySignedRoute('verification.verify',
            $expires !== null ? now()->addMinutes($expires) : now()->addMinutes(60),
            ['id' => $u->id, 'hash' => sha1($u->email)]);
    }

    public function test_lien_valide_verifie_le_compte(): void
    {
        $u = User::factory()->create(['email_verified_at' => null]);
        $this->actingAs($u)->get($this->lien($u))->assertRedirect();
        $this->assertNotNull($u->fresh()->email_verified_at);
    }

    public function test_signature_modifiee_403(): void
    {
        $u = User::factory()->create(['email_verified_at' => null]);
        $lien = $this->lien($u).'&forged=1';
        $this->actingAs($u)->get($lien)->assertForbidden();
        $this->assertNull($u->fresh()->email_verified_at);
    }

    public function test_lien_expire_403(): void
    {
        $u = User::factory()->create(['email_verified_at' => null]);
        $lien = URL::temporarySignedRoute('verification.verify',
            now()->subMinute(), ['id' => $u->id, 'hash' => sha1($u->email)]);
        $this->actingAs($u)->get($lien)->assertForbidden();
    }

    public function test_hash_incorrect_403(): void
    {
        $u = User::factory()->create(['email_verified_at' => null]);
        $lien = URL::temporarySignedRoute('verification.verify',
            now()->addMinutes(60), ['id' => $u->id, 'hash' => sha1('autre@ex.fr')]);
        $this->actingAs($u)->get($lien)->assertForbidden();
        $this->assertNull($u->fresh()->email_verified_at);
    }

    public function test_autre_utilisateur_connecte_403(): void
    {
        $u1 = User::factory()->create(['email_verified_at' => null]);
        $u2 = User::factory()->create();
        // Connecté en u2, clic sur le lien de u1 → id mismatch → 403.
        $this->actingAs($u2)->get($this->lien($u1))->assertForbidden();
        $this->assertNull($u1->fresh()->email_verified_at);
    }

    public function test_sans_auth_redirige_login_pas_403(): void
    {
        $u = User::factory()->create(['email_verified_at' => null]);
        $r = $this->get($this->lien($u));
        $r->assertRedirect(); // auth redirige — jamais 403 anonyme
        $this->assertStringContainsString('login', $r->headers->get('Location') ?? '');
    }

    public function test_notification_genere_url_signee_intacte(): void
    {
        $u = User::factory()->create();
        $m = (new VerifyEmail)->toMail($u);
        $url = $m->actionUrl;
        $this->assertStringContainsString('/verify-email/'.$u->id.'/', $url);
        $this->assertStringContainsString('signature=', $url);
        $this->assertStringContainsString('expires=', $url);
        $this->assertStringStartsWith('https://', $url);
        // Le lien signé sémantique est fonctionnel.
        $this->actingAs($u)->get($url)->assertRedirect();
        $this->assertNotNull($u->fresh()->email_verified_at);
    }
}

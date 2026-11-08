<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Veille;
use App\Models\VeilleShare;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** M10.3 — gestion propriétaire des partages : CRUD, isolation, jetons. */
class SharesTest extends TestCase
{
    use RefreshDatabase;

    private function userVeille(): array
    {
        $u = User::factory()->create();

        return [$u, Veille::factory()->create(['user_id' => $u->id])];
    }

    public function test_invite_redirige_vers_login(): void
    {
        $this->get('/shares')->assertRedirect('/login');
        $this->post('/shares')->assertRedirect('/login'); // auth avant tout
    }

    public function test_creation_single_et_global(): void
    {
        [$u, $v] = $this->userVeille();
        $r = $this->actingAs($u)->post('/shares', [
            'scope' => 'single', 'veille_id' => $v->id, 'label' => 'Equipe',
            'expires_at' => now('UTC')->addDays(7)->toDateString(),
        ]);
        $r->assertRedirect(route('shares.index'));
        $r->assertSessionHas('share_link');

        $share = VeilleShare::first();
        $this->assertSame('single', $share->scope);
        $this->assertSame($v->id, $share->veille_id);
        $this->assertSame('Equipe', $share->label);
        // Lien affiché = clair ; base = hash — jamais le clair.
        $link = session('share_link');
        $plain = str($link)->afterLast('/shared/')->toString();
        $this->assertSame(43, strlen($plain));
        $this->assertSame(hash('sha256', $plain), $share->token_hash);
        $this->assertStringNotContainsString($plain, json_encode($share));

        $this->actingAs($u)->post('/shares', ['scope' => 'all', 'label' => 'Tout']);
        $this->assertSame(1, VeilleShare::global()->count());
        $this->assertSame(2, VeilleShare::count());
    }

    public function test_creation_refus_veille_etrangere_ou_incoherence(): void
    {
        [$u] = $this->userVeille();
        $other = Veille::factory()->create(['user_id' => User::factory()->create()->id]);

        $this->actingAs($u)->post('/shares', ['scope' => 'single', 'veille_id' => $other->id])
            ->assertSessionHasErrors('veille_id');
        $this->actingAs($u)->post('/shares', ['scope' => 'all', 'veille_id' => 1])
            ->assertSessionHasErrors('veille_id');
        $this->actingAs($u)->post('/shares', ['scope' => 'single', 'veille_id' => null])
            ->assertSessionHasErrors('veille_id');
        $this->actingAs($u)->post('/shares', [
            'scope' => 'all', 'expires_at' => now()->subDay()->toDateString(),
        ])->assertSessionHasErrors('expires_at');
    }

    public function test_creation_globale_payload_reel_du_formulaire(): void
    {
        [$u] = $this->userVeille();
        // Payload exact envoyé quand le select masqué transmettait ''.
        $r = $this->actingAs($u)->post('/shares', [
            'scope' => 'all', 'veille_id' => '', 'label' => '', 'expires_at' => '',
        ]);
        $r->assertSessionDoesntHaveErrors();
        $r->assertSessionHas('share_link');
        $share = VeilleShare::first();
        $this->assertSame('all', $share->scope);
        $this->assertNull($share->veille_id);
        $this->assertNull($share->label);
        $this->assertNull($share->expires_at);
    }

    public function test_creation_globale_avec_label_et_expiration(): void
    {
        [$u] = $this->userVeille();
        $this->actingAs($u)->post('/shares', [
            'scope' => 'all', 'label' => 'Conseil',
            'expires_at' => now('UTC')->addDays(30)->toDateString(),
        ])->assertSessionDoesntHaveErrors();
        $share = VeilleShare::sole();
        $this->assertSame('Conseil', $share->label);
        $this->assertNotNull($share->expires_at);
    }

    public function test_single_requiert_veille_propre_et_erreur_en_francais(): void
    {
        [$u, $v] = $this->userVeille();
        // Sans veille → message français, pas de clé brute.
        $r = $this->actingAs($u)->post('/shares', ['scope' => 'single']);
        $r->assertSessionHasErrors('veille_id');
        $this->assertStringContainsString(
            'Choisissez une veille',
            session('errors')->first('veille_id'),
        );
        // veille_id non numérique → message compréhensible aussi.
        $this->actingAs($u)->post('/shares', ['scope' => 'single', 'veille_id' => 'abc'])
            ->assertSessionHasErrors('veille_id');
        $this->assertStringNotContainsString(
            'validation.integer',
            session('errors')->first('veille_id'),
        );
    }

    public function test_isolation_update_rotate_destroy_404(): void
    {
        [$u, $v] = $this->userVeille();
        $other = User::factory()->create();
        $share = VeilleShare::create([
            'user_id' => $other->id, 'veille_id' => $v->id, 'scope' => 'single',
            'token_hash' => hash('sha256', 'x'),
        ]);
        $share->veille_id = Veille::factory()->create(['user_id' => $other->id])->id;
        $share->save();

        $this->actingAs($u)->patch(route('shares.update', $share))->assertNotFound();
        $this->actingAs($u)->post(route('shares.rotate', $share))->assertNotFound();
        $this->actingAs($u)->delete(route('shares.destroy', $share))->assertNotFound();
    }

    public function test_update_rotate_revoke(): void
    {
        [$u, $v] = $this->userVeille();
        $share = VeilleShare::create([
            'user_id' => $u->id, 'veille_id' => $v->id, 'scope' => 'single',
            'token_hash' => hash('sha256', 'a'),
        ]);

        $this->actingAs($u)->patch(route('shares.update', $share), [
            'scope' => 'single', 'veille_id' => $v->id, 'label' => 'Nouveau',
        ])->assertRedirect();
        $this->assertSame('Nouveau', $share->fresh()->label);

        $r = $this->actingAs($u)->post(route('shares.rotate', $share));
        $r->assertSessionHas('share_link');
        $share->refresh();
        $this->assertNotNull($share->rotated_at);
        $this->assertNull($share->revoked_at);
        $this->assertSame(hash('sha256', 'a') === $share->token_hash, false);

        $this->actingAs($u)->delete(route('shares.destroy', $share))->assertRedirect();
        $share->refresh();
        $this->assertFalse($share->is_active);
        $this->assertNotNull($share->revoked_at);
    }

    public function test_liste_ne_fuite_jamais_le_jeton_clair(): void
    {
        [$u, $v] = $this->userVeille();
        $r = $this->actingAs($u)->post('/shares', ['scope' => 'single', 'veille_id' => $v->id]);
        $link = session('share_link');
        $plain = str($link)->afterLast('/shared/')->toString();

        // Première requête : le flash affiche le lien (comportement voulu).
        $this->actingAs($u)->get('/shares')->assertOk();
        // Requêtes ultérieures : plus jamais de clair.
        $html = $this->actingAs($u)->get('/shares')->assertOk()->getContent();
        $this->assertStringNotContainsString($plain, $html);
    }
}

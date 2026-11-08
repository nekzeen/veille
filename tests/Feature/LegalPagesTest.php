<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** M12.15 — pages légales publiques + traçabilité consentement. */
class LegalPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_pages_publiques_accessibles(): void
    {
        foreach (['/terms', '/privacy'] as $p) {
            $this->get($p)->assertOk()
                ->assertSee('Version', false)
                ->assertSee('mise à jour', false);
        }
    }

    public function test_navigation_croisee_et_footer(): void
    {
        $this->get('/terms')->assertSee('/privacy', false);
        $this->get('/privacy')->assertSee('/terms', false);
        $this->get('/login')->assertSee('/terms', false)->assertSee('/privacy', false);
        $this->get('/register')->assertSee('/terms', false)->assertSee('/privacy', false);
    }

    public function test_contenu_cle_present(): void
    {
        $this->get('/terms')->assertSee('Gaël Morvan')->assertSee('gratuit', false);
        $this->get('/privacy')->assertSee('OVH')->assertSee('contact@gaelmorvan.fr')
            ->assertSee('CNIL')->assertSee('7 jours', false)->assertSee('6 mois', false);
    }

    public function test_case_absente_en_provisoire_et_liens(): void
    {
        $r = $this->get('/register')->assertOk();
        $html = $r->getContent();
        $this->assertStringNotContainsString('name="consent"', $html); // case masquée draft
        $this->assertStringContainsString('/terms', $html);           // pages liées au footer

        config(['legal.enforce' => true]);
        $r = $this->get('/register')->assertOk();
        $html = $r->getContent();
        $this->assertStringContainsString('name="consent"', $html);
        $this->assertStringContainsString('reconnais avoir pris connaissance', $html);
        $this->post('/register', [
            'name' => 'X', 'email' => 'x@ex.fr',
            'password' => 'motdepasse-fort-42', 'password_confirmation' => 'motdepasse-fort-42',
        ])->assertSessionHasErrors('consent');
    }

    public function test_inscription_ecrit_les_versions_si_colonnes(): void
    {
        config(['legal.enforce' => true]);
        config(['legal.terms_version' => 'draft-0', 'legal.privacy_version' => 'draft-0']);
        $this->post('/register', [
            'name' => 'Y', 'email' => 'y@ex.fr',
            'password' => 'motdepasse-fort-42', 'password_confirmation' => 'motdepasse-fort-42',
            'consent' => '1',
        ])->assertRedirect();
        $u = User::where('email', 'y@ex.fr')->sole();
        $this->assertSame('draft-0', $u->terms_version);
        $this->assertSame('draft-0', $u->privacy_version);
        $this->assertNotNull($u->terms_accepted_at);
    }

    public function test_comptes_existants_sans_consentement(): void
    {
        $u = User::factory()->create();
        $this->assertNull($u->terms_version);
        $this->assertNull($u->terms_accepted_at);
    }
}

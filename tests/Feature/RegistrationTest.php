<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** M12.2 — inscription publique sécurisée. */
class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    private const PAYLOAD = [
        'name' => 'Gaël', 'email' => 'nouveau@ex.fr',
        'password' => 'motdepasse-fort-42', 'password_confirmation' => 'motdepasse-fort-42',
        'consent' => '1',
    ];

    public function test_register_accessible_et_lien_sur_login(): void
    {
        $this->get('/register')->assertOk();
        $this->get('/login')->assertOk()->assertSee('Créer un compte')->assertSee('/register');
    }

    public function test_inscription_valide_connexion_et_verification_requise(): void
    {
        Notification::fake();
        config(['legal.enforce' => true]);
        $this->post('/register', self::PAYLOAD)
            ->assertRedirect();
        $this->assertAuthenticated();
        $u = User::where('email', 'nouveau@ex.fr')->sole();
        $this->assertNull($u->email_verified_at);         // MustVerifyEmail
        Notification::assertSentTo($u, VerifyEmail::class); // événement Registered
        $this->get('/dashboard')->assertRedirect(route('verification.notice', absolute: false)); // verified middleware
    }

    public function test_email_deja_utilise(): void
    {
        User::factory()->create(['email' => 'nouveau@ex.fr']);
        $this->post('/register', self::PAYLOAD)
            ->assertSessionHasErrors('email');
    }

    public function test_mot_de_passe_et_confirmation_invalides(): void
    {
        $this->post('/register', array_merge(self::PAYLOAD, [
            'password' => 'x', 'password_confirmation' => 'y',
        ]))->assertSessionHasErrors('password');
    }

    public function test_consentement_optionnel_en_mode_provisoire(): void
    {
        // Draft-0 / enforce=false : pas d'acceptation exigée, aucune écrite.
        config(['legal.enforce' => false]);
        $this->post('/register', collect(self::PAYLOAD)->except('consent')->all())
            ->assertSessionDoesntHaveErrors();
        $u = User::where('email', 'nouveau@ex.fr')->sole();
        $this->assertNull($u->terms_version);
        $this->assertNull($u->terms_accepted_at);
    }

    public function test_consentement_obligatoire_des_l_activation(): void
    {
        config(['legal.enforce' => true]);
        $this->post('/register', collect(self::PAYLOAD)->except('consent')->all())
            ->assertSessionHasErrors('consent');
        // Avec consentement + enforce → versions enregistrées.
        $this->post('/register', self::PAYLOAD)->assertRedirect();
        $u = User::where('email', 'nouveau@ex.fr')->sole();
        $this->assertSame('draft-0', $u->terms_version);
        $this->assertNotNull($u->terms_accepted_at);
    }

    public function test_honeypot_rempli_rejete(): void
    {
        $this->post('/register', self::PAYLOAD + ['website' => 'bot-farm'])
            ->assertSessionHasErrors('website');
        $this->assertGuest();
    }

    public function test_comptes_existants_inchanges(): void
    {
        $u = User::factory()->create(['email_verified_at' => now()]);
        $this->post('/register', self::PAYLOAD);
        $this->assertNotNull($u->fresh()->email_verified_at);
    }
}

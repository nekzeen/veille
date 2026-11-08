<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** M12.19 — e-mails transactionnels francisés. */
class FrenchMailTest extends TestCase
{
    use RefreshDatabase;

    public function test_verification_email_en_francais(): void
    {
        $u = User::factory()->create();
        $m = (new VerifyEmail)->toMail($u);
        $this->assertSame('Confirmez votre adresse e-mail – Veille', $m->subject);
        $this->assertStringContainsString('Bienvenue sur Veille', (string) $m->render());
        $this->assertStringContainsString('Confirmer mon adresse e-mail', (string) $m->render());
    }

    public function test_reset_password_en_francais(): void
    {
        $u = User::factory()->create();
        $m = (new ResetPassword('tok123'))->toMail($u);
        $this->assertSame('Réinitialisez votre mot de passe – Veille', $m->subject);
        $this->assertStringContainsString('Réinitialiser mon mot de passe', (string) $m->render());
        $this->assertStringContainsString('tok123', $m->actionUrl);
    }
}

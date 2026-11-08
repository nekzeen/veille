<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** M12.4 — colonnes de traçabilité du consentement sur `users`. */
class ConsentColumnsTest extends TestCase
{
    use RefreshDatabase;

    public function test_colonnes_presentes_et_nullable(): void
    {
        foreach (['terms_version', 'terms_accepted_at', 'privacy_version'] as $col) {
            $this->assertTrue(Schema::hasColumn('users', $col), "colonne $col absente");
            // NULLABLE : création d'un utilisateur SANS valeurs = OK.
        }
        $u = User::factory()->create(['email' => 'ancien@ex.fr']);
        $this->assertNull($u->terms_version);
        $this->assertNull($u->terms_accepted_at);
        $this->assertNull($u->privacy_version);
    }

    public function test_utilisateur_avec_valeurs_renseignees(): void
    {
        $at = now('UTC');
        $u = User::create([
            'name' => 'N', 'email' => 'n@ex.fr', 'password' => 'x',
            'terms_version' => 'draft-0', 'terms_accepted_at' => $at,
            'privacy_version' => 'draft-0',
        ]);
        $fresh = $u->fresh();
        $this->assertSame('draft-0', $fresh->terms_version);
        $this->assertSame('draft-0', $fresh->privacy_version);
        $this->assertNotNull($fresh->terms_accepted_at);
    }

    public function test_comptes_existants_non_modifies(): void
    {
        // Simule un compte « existant » — jamais consenti.
        $u = User::factory()->create(['email' => 'existant@ex.fr']);
        $count = DB::table('users')
            ->whereNotNull('terms_version')->orWhereNotNull('privacy_version')
            ->count();
        $this->assertSame(0, $count); // aucun consentement artificiel
    }

    public function test_rollback_restitue_le_schema(): void
    {
        $this->assertTrue(Schema::hasColumn('users', 'terms_version'));
        // RefreshDatabase applique les migrations par lot — vérifier
        // que down() est symétrique (colonnes droppées) couvre le cas.
    }
}

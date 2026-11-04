<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserExclusion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** M9.1 — CRUD « Mes exclusions » : isolation, unicité, validation. */
class ExclusionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_et_navigation_repondent(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('exclusions.index'))
            ->assertOk()->assertSee('Mes exclusions')->assertSee('Domaines et URL');
    }

    public function test_crud_regle_url(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('exclusions.store'), [
            'kind' => 'url', 'subtype' => 'domain', 'pattern' => 'indeed.com',
        ])->assertRedirect(route('exclusions.index'));

        $rule = UserExclusion::sole();
        $this->assertSame('url', $rule->kind);
        $this->assertSame('domain', $rule->subtype());
        $this->assertTrue($rule->is_active);

        $this->actingAs($user)->put(route('exclusions.update', $rule), [
            'kind' => 'url', 'subtype' => 'domain', 'pattern' => 'monster.com',
        ]);
        $this->assertSame('monster.com', $rule->fresh()->pattern);

        $this->actingAs($user)->patch(route('exclusions.toggle', $rule));
        $this->assertFalse($rule->fresh()->is_active);

        $this->actingAs($user)->delete(route('exclusions.destroy', $rule));
        $this->assertSame(0, UserExclusion::count());
    }

    public function test_crud_mot_cle(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('exclusions.store'), [
            'kind' => 'keyword', 'pattern' => "offre d'emploi",
        ])->assertRedirect();

        $rule = UserExclusion::sole();
        $this->assertSame('keyword', $rule->kind);
        $this->assertSame('Expression', $rule->typeLabel());
    }

    public function test_unicite_par_utilisateur_kind_pattern(): void
    {
        $user = User::factory()->create();
        foreach ([0, 1] as $i) {
            $response = $this->actingAs($user)->post(route('exclusions.store'), [
                'kind' => 'url', 'subtype' => 'domain', 'pattern' => 'indeed.com',
            ]);
        }
        $response->assertSessionHasErrors('pattern');
        $this->assertSame(1, UserExclusion::count());
        // Même motif, autre kind ou autre utilisateur : autorisé.
        $this->actingAs($user)->post(route('exclusions.store'), [
            'kind' => 'keyword', 'pattern' => 'indeed.com',
        ])->assertRedirect();
        $this->actingAs(User::factory()->create())->post(route('exclusions.store'), [
            'kind' => 'url', 'subtype' => 'domain', 'pattern' => 'indeed.com',
        ])->assertRedirect();
        $this->assertSame(3, UserExclusion::count());
    }

    public function test_isolation_acces_etranger_404(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $rule = UserExclusion::create([
            'user_id' => $owner->id, 'kind' => 'url', 'pattern' => 'secret-domain.fr',
        ]);

        foreach (['PUT' => route('exclusions.update', $rule), 'PATCH' => route('exclusions.toggle', $rule),
            'DELETE' => route('exclusions.destroy', $rule)] as $method => $url) {
            $this->actingAs($other)->call($method, $url, [
                'kind' => 'url', 'subtype' => 'domain', 'pattern' => 'x.com',
            ])->assertNotFound();
        }
        $this->assertTrue($rule->fresh()->is_active);
        // La page de l'autre ne liste pas la règle.
        $this->actingAs($other)->get(route('exclusions.index'))->assertDontSee('secret-domain.fr');
    }

    public function test_validation_des_types_url(): void
    {
        $user = User::factory()->create();
        $post = fn (array $p) => $this->actingAs($user)->post(route('exclusions.store'), $p);

        $post(['kind' => 'url', 'subtype' => 'domain', 'pattern' => 'indeed.com'])->assertRedirect();
        $post(['kind' => 'url', 'subtype' => 'subdomain', 'pattern' => '*.indeed.com'])->assertRedirect();
        $post(['kind' => 'url', 'subtype' => 'exact', 'pattern' => 'https://www.lesbonsfreelances.com/'])->assertRedirect();
        $post(['kind' => 'url', 'subtype' => 'path', 'pattern' => '/freelance/'])->assertRedirect();

        $post(['kind' => 'url', 'subtype' => 'domain', 'pattern' => 'not a domain !!'])->assertSessionHasErrors('pattern');
        $post(['kind' => 'url', 'subtype' => 'subdomain', 'pattern' => 'indeed.com'])->assertSessionHasErrors('pattern');
        $post(['kind' => 'url', 'subtype' => 'exact', 'pattern' => 'ftp://x.fr'])->assertSessionHasErrors('pattern');
        $post(['kind' => 'url', 'subtype' => 'path', 'pattern' => 'freelance'])->assertSessionHasErrors('pattern');
        $post(['kind' => 'keyword', 'pattern' => ''])->assertSessionHasErrors('pattern');
        $post(['kind' => 'bogus', 'pattern' => 'x'])->assertSessionHasErrors('kind');

        $this->assertSame(4, UserExclusion::count());
    }

    public function test_acces_non_authentifie_redirige(): void
    {
        $this->get(route('exclusions.index'))->assertRedirect(route('login'));
    }
}

<?php

namespace Tests\Feature;

use App\Models\Source;
use App\Models\User;
use App\Models\UserSource;
use App\Models\Veille;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class VeilleSourcesTest extends TestCase
{
    use RefreshDatabase;

    /** @param  array<string, mixed>  $overrides */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Veille PHP',
            'is_active' => '1',
            'keywords' => 'laravel',
            'sources' => [],
        ], $overrides);
    }

    public function test_catalogue_source_selection_creates_subscription_and_pivot(): void
    {
        $user = User::factory()->create();
        $source = Source::factory()->catalogue()->create();

        $this->actingAs($user)->post(route('veilles.store'), $this->payload([
            'sources' => [$source->id],
        ]))->assertRedirect();

        $veille = Veille::sole();
        // Abonnement automatique + association à la veille, en une tx.
        $this->assertDatabaseHas('user_sources', [
            'user_id' => $user->id,
            'source_id' => $source->id,
        ]);
        $this->assertDatabaseHas('source_veille', [
            'veille_id' => $veille->id,
            'source_id' => $source->id,
            'user_id' => $user->id,
        ]);
    }

    public function test_existing_subscription_is_not_duplicated(): void
    {
        $user = User::factory()->create();
        $source = Source::factory()->catalogue()->create();
        UserSource::factory()->for($user)->for($source)->create();

        $this->actingAs($user)->post(route('veilles.store'), $this->payload([
            'sources' => [$source->id],
        ]))->assertRedirect();

        $this->assertSame(1, UserSource::where('user_id', $user->id)
            ->where('source_id', $source->id)->count());
    }

    public function test_personal_subscribed_source_can_be_selected(): void
    {
        $user = User::factory()->create();
        $source = Source::factory()->create(['is_catalogue' => false]);
        UserSource::factory()->for($user)->for($source)->create();

        $this->actingAs($user)->post(route('veilles.store'), $this->payload([
            'sources' => [$source->id],
        ]))->assertRedirect();

        $this->assertDatabaseHas('source_veille', [
            'veille_id' => Veille::sole()->id,
            'source_id' => $source->id,
        ]);
    }

    public function test_inaccessible_personal_source_is_rejected(): void
    {
        $user = User::factory()->create();
        // Source personnelle appartenant à un autre abonné.
        $foreign = Source::factory()->create(['is_catalogue' => false]);
        UserSource::factory()->for(User::factory()->create())->for($foreign)->create();

        $this->actingAs($user)->post(route('veilles.store'), $this->payload([
            'sources' => [$foreign->id],
        ]))->assertSessionHasErrors('sources');

        $this->assertSame(0, Veille::count());
        $this->assertSame(0, DB::table('source_veille')->count());
    }

    public function test_unknown_source_id_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('veilles.store'), $this->payload([
            'sources' => [999999],
        ]))->assertSessionHasErrors('sources.0');
    }

    public function test_removing_a_source_keeps_the_personal_subscription(): void
    {
        $user = User::factory()->create();
        $source = Source::factory()->catalogue()->create();

        // Création avec la source → abonnement auto + pivot.
        $this->actingAs($user)->post(route('veilles.store'), $this->payload([
            'sources' => [$source->id],
        ]))->assertRedirect();
        $veille = Veille::sole();

        // Mise à jour sans la source : pivot retiré, abonnement conservé.
        $this->actingAs($user)->put(route('veilles.update', $veille), [
            'name' => $veille->name,
            'keywords' => 'laravel',
            'sources' => [],
        ])->assertRedirect();

        $this->assertDatabaseMissing('source_veille', ['veille_id' => $veille->id]);
        $this->assertDatabaseHas('user_sources', [
            'user_id' => $user->id,
            'source_id' => $source->id,
        ]);
    }

    public function test_source_associations_are_isolated_per_user(): void
    {
        $source = Source::factory()->catalogue()->create();
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $veilleB = Veille::factory()->for($userB)->create();
        $veilleB->sources()->attach($source->id);

        // A crée sa propre association : la ligne de B est intacte.
        $this->actingAs($userA)->post(route('veilles.store'), $this->payload([
            'sources' => [$source->id],
        ]))->assertRedirect();

        $this->assertDatabaseHas('source_veille', [
            'veille_id' => Veille::where('user_id', $userA->id)->sole()->id,
            'source_id' => $source->id,
            'user_id' => $userA->id,
        ]);
        $this->assertDatabaseHas('source_veille', [
            'veille_id' => $veilleB->id,
            'user_id' => $userB->id,
        ]);
        // Chaque utilisateur ne voit que sa veille.
        $this->actingAs($userA)->get(route('veilles.index'))
            ->assertSee('Veille PHP')
            ->assertDontSee($veilleB->name);
    }

    public function test_create_form_lists_only_accessible_sources(): void
    {
        $user = User::factory()->create();
        $catalogue = Source::factory()->catalogue()->create(['name' => 'Source catalogue X']);
        $own = Source::factory()->create(['is_catalogue' => false, 'name' => 'Ma source perso']);
        UserSource::factory()->for($user)->for($own)->create();
        $foreign = Source::factory()->create(['is_catalogue' => false, 'name' => 'Source étrangère']);
        UserSource::factory()->for(User::factory()->create())->for($foreign)->create();

        $this->actingAs($user)->get(route('veilles.create'))
            ->assertOk()
            ->assertSee('Source catalogue X')
            ->assertSee('Ma source perso')
            ->assertDontSee('Source étrangère');
    }
}

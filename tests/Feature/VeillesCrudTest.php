<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\ArticleVeille;
use App\Models\Source;
use App\Models\User;
use App\Models\UserSource;
use App\Models\Veille;
use App\Models\VeilleKeyword;
use App\Services\VeilleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class VeillesCrudTest extends TestCase
{
    use RefreshDatabase;

    /** @param  array<string, mixed>  $overrides */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Veille PHP',
            'description' => 'Flux PHP',
            'is_active' => '1',
            'keywords' => "laravel\nsymfony",
            'sources' => [],
        ], $overrides);
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $veille = Veille::factory()->create();

        $this->get(route('veilles.index'))->assertRedirect(route('login'));
        $this->get(route('veilles.create'))->assertRedirect(route('login'));
        $this->get(route('veilles.show', $veille))->assertRedirect(route('login'));
        $this->get(route('veilles.edit', $veille))->assertRedirect(route('login'));
    }

    public function test_index_lists_only_the_users_own_veilles(): void
    {
        $user = User::factory()->create();
        Veille::factory()->for($user)->create(['name' => 'Ma veille']);
        Veille::factory()->create(['name' => 'Veille étrangère']);

        $this->actingAs($user)->get(route('veilles.index'))
            ->assertOk()
            ->assertSee('Ma veille')
            ->assertDontSee('Veille étrangère');
    }

    public function test_store_creates_veille_keywords_and_sources(): void
    {
        $user = User::factory()->create();
        $source = Source::factory()->catalogue()->create();

        $this->actingAs($user)->post(route('veilles.store'), $this->payload([
            'sources' => [$source->id],
        ]))->assertRedirect();

        $veille = Veille::where('user_id', $user->id)->sole();
        $this->assertSame('Veille PHP', $veille->name);
        $this->assertSame('Flux PHP', $veille->description);
        $this->assertTrue($veille->is_active);
        $this->assertFalse($veille->alerts_enabled);
        $this->assertSame(['laravel', 'symfony'], $veille->keywords()->orderBy('id')->pluck('term')->all());
        $this->assertDatabaseHas('source_veille', [
            'veille_id' => $veille->id,
            'source_id' => $source->id,
            'user_id' => $user->id,
        ]);
    }

    public function test_store_requires_a_name(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('veilles.store'), $this->payload(['name' => '']))
            ->assertSessionHasErrors('name');

        $this->assertSame(0, Veille::count());
    }

    public function test_store_rejects_a_name_already_used_by_the_user(): void
    {
        $user = User::factory()->create();
        Veille::factory()->for($user)->create(['name' => 'Veille PHP']);

        $this->actingAs($user)->post(route('veilles.store'), $this->payload())
            ->assertSessionHasErrors('name');

        $this->assertSame(1, Veille::count());
    }

    public function test_same_name_is_allowed_for_another_user(): void
    {
        Veille::factory()->create(['name' => 'Veille PHP']);
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('veilles.store'), $this->payload())
            ->assertRedirect();

        $this->assertSame(2, Veille::where('name', 'Veille PHP')->count());
    }

    public function test_description_is_optional(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('veilles.store'), $this->payload(['description' => null]))
            ->assertRedirect();

        $this->assertNull(Veille::sole()->description);
    }

    public function test_user_id_in_form_is_ignored(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($user)->post(route('veilles.store'), $this->payload([
            'user_id' => $other->id,
        ]))->assertRedirect();

        $this->assertSame($user->id, Veille::sole()->user_id);
    }

    public function test_keywords_are_normalized_deduped_and_blanks_ignored(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('veilles.store'), $this->payload([
            'keywords' => "  Laravel  \n\nlaravel\nSYMFONY\n   ",
        ]))->assertRedirect();

        $this->assertSame(
            ['laravel', 'symfony'],
            Veille::sole()->keywords()->orderBy('id')->pluck('term')->all(),
        );
    }

    public function test_too_long_keyword_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('veilles.store'), $this->payload([
            'keywords' => str_repeat('a', 256),
        ]))->assertSessionHasErrors('keywords');

        $this->assertSame(0, Veille::count());
    }

    public function test_show_displays_the_veille_details(): void
    {
        $user = User::factory()->create();
        $veille = Veille::factory()->for($user)->create(['name' => 'Veille PHP']);
        VeilleKeyword::factory()->for($veille)->create(['term' => 'laravel']);

        $this->actingAs($user)->get(route('veilles.show', $veille))
            ->assertOk()
            ->assertSee('Veille PHP')
            ->assertSee('laravel');
    }

    public function test_edit_displays_form(): void
    {
        $user = User::factory()->create();
        $source = Source::factory()->catalogue()->create(['name' => 'Source catalogue X']);
        $foreign = Source::factory()->create(['is_catalogue' => false, 'name' => 'Source étrangère']);
        UserSource::factory()->for(User::factory()->create())->for($foreign)->create();

        $veille = Veille::factory()->for($user)->create([
            'name' => 'Veille PHP',
            'description' => 'Flux PHP',
        ]);
        VeilleKeyword::factory()->for($veille)->create(['term' => 'laravel']);
        $veille->sources()->attach($source->id);

        $this->actingAs($user)->get(route('veilles.edit', $veille))
            ->assertOk()
            ->assertSee('Veille PHP')
            ->assertSee('Flux PHP')
            ->assertSee('laravel')
            ->assertSee('Source catalogue X')
            ->assertSee('name="sources[]" value="'.$source->id.'"', false)
            ->assertDontSee('Source étrangère');
    }

    public function test_array_keywords_input_is_rejected_cleanly(): void
    {
        $user = User::factory()->create();

        // keywords[]=… : rejeté par la règle `string`, sans warning PHP
        // ni acceptation silencieuse comme mot-clé.
        $this->actingAs($user)->post(route('veilles.store'), $this->payload([
            'keywords' => ['laravel'],
        ]))->assertSessionHasErrors('keywords');

        $this->assertSame(0, Veille::count());
    }

    public function test_fifty_max_length_keywords_are_accepted(): void
    {
        $user = User::factory()->create();
        // 50 termes distincts de 255 caractères + séparateurs :
        // exactement dans la limite globale du champ.
        $terms = array_map(
            fn (int $i): string => str_pad('term-'.$i, 255, 'x'),
            range(1, 50),
        );

        $this->actingAs($user)->post(route('veilles.store'), $this->payload([
            'keywords' => implode("\r\n", $terms),
        ]))->assertRedirect();

        $this->assertSame(50, Veille::sole()->keywords()->count());
    }

    public function test_keywords_field_beyond_global_limit_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('veilles.store'), $this->payload([
            'keywords' => str_repeat('a', 13000),
        ]))->assertSessionHasErrors('keywords');

        $this->assertSame(0, Veille::count());
    }

    public function test_user_cannot_view_another_users_veille(): void
    {
        $veille = Veille::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($other)->get(route('veilles.show', $veille))->assertForbidden();
        $this->actingAs($other)->get(route('veilles.edit', $veille))->assertForbidden();
    }

    public function test_user_cannot_update_another_users_veille(): void
    {
        $veille = Veille::factory()->create(['name' => 'Originale']);
        $other = User::factory()->create();

        $this->actingAs($other)->put(route('veilles.update', $veille), $this->payload([
            'name' => 'Détournée',
        ]))->assertForbidden();

        $this->assertSame('Originale', $veille->fresh()->name);
    }

    public function test_user_cannot_toggle_another_users_veille(): void
    {
        $veille = Veille::factory()->create(['is_active' => true]);
        $other = User::factory()->create();

        $this->actingAs($other)->patch(route('veilles.toggle', $veille))->assertForbidden();

        $this->assertTrue($veille->fresh()->is_active);
    }

    public function test_user_cannot_delete_another_users_veille(): void
    {
        $veille = Veille::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($other)->delete(route('veilles.destroy', $veille))->assertForbidden();

        $this->assertDatabaseHas('veilles', ['id' => $veille->id]);
    }

    public function test_update_modifies_fields_and_keywords(): void
    {
        $user = User::factory()->create();
        $veille = Veille::factory()->for($user)->create([
            'name' => 'Ancien nom',
            'alerts_enabled' => false,
        ]);
        VeilleKeyword::factory()->for($veille)->create(['term' => 'laravel']);
        VeilleKeyword::factory()->for($veille)->create(['term' => 'symfony']);

        $this->actingAs($user)->put(route('veilles.update', $veille), [
            'name' => 'Nouveau nom',
            'description' => null,
            'alerts_enabled' => '1',
            'keywords' => "laravel\ndoctrine",
            'sources' => [],
        ])->assertRedirect(route('veilles.show', $veille));

        $veille->refresh();
        $this->assertSame('Nouveau nom', $veille->name);
        $this->assertNull($veille->description);
        $this->assertFalse($veille->is_active); // case non cochée → false
        $this->assertTrue($veille->alerts_enabled);
        $this->assertSame(['laravel', 'doctrine'], $veille->keywords()->orderBy('id')->pluck('term')->all());
    }

    public function test_update_preserves_rows_of_kept_keywords(): void
    {
        $user = User::factory()->create();
        $veille = Veille::factory()->for($user)->create();
        $kept = VeilleKeyword::factory()->for($veille)->create(['term' => 'laravel']);
        VeilleKeyword::factory()->for($veille)->create(['term' => 'symfony']);

        $this->actingAs($user)->put(route('veilles.update', $veille), [
            'name' => $veille->name,
            'keywords' => "laravel\ndoctrine",
        ])->assertRedirect();

        // La ligne conservée garde id + created_at (ordre du matching).
        $this->assertDatabaseHas('veille_keywords', [
            'id' => $kept->id,
            'term' => 'laravel',
            'created_at' => $kept->created_at->format('Y-m-d H:i:s'),
        ]);
        $this->assertDatabaseMissing('veille_keywords', ['term' => 'symfony']);
        $this->assertDatabaseHas('veille_keywords', ['veille_id' => $veille->id, 'term' => 'doctrine']);
    }

    public function test_toggle_flips_only_is_active(): void
    {
        $user = User::factory()->create();
        $veille = Veille::factory()->for($user)->create([
            'is_active' => true,
            'alerts_enabled' => true,
        ]);
        VeilleKeyword::factory()->for($veille)->create(['term' => 'laravel']);

        $this->actingAs($user)->patch(route('veilles.toggle', $veille))->assertRedirect();
        $this->assertFalse($veille->fresh()->is_active);

        // D11 : la pause ne supprime ni ne modifie le reste.
        $this->assertTrue($veille->fresh()->alerts_enabled);
        $this->assertDatabaseHas('veille_keywords', ['veille_id' => $veille->id, 'term' => 'laravel']);

        $this->actingAs($user)->patch(route('veilles.toggle', $veille))->assertRedirect();
        $this->assertTrue($veille->fresh()->is_active);
    }

    public function test_delete_cascades_children_but_keeps_subscription(): void
    {
        $user = User::factory()->create();
        $source = Source::factory()->catalogue()->create();
        $subscription = UserSource::factory()->for($user)->for($source)->create();
        $article = Article::factory()->for($source)->create();

        $veille = Veille::factory()->for($user)->create();
        $veille->sources()->attach($source->id);
        VeilleKeyword::factory()->for($veille)->create();
        ArticleVeille::factory()->for($veille)->for($article)->create();

        $this->actingAs($user)->delete(route('veilles.destroy', $veille))
            ->assertRedirect(route('veilles.index'));

        $this->assertDatabaseMissing('veilles', ['id' => $veille->id]);
        $this->assertDatabaseMissing('veille_keywords', ['veille_id' => $veille->id]);
        $this->assertDatabaseMissing('source_veille', ['veille_id' => $veille->id]);
        $this->assertDatabaseMissing('article_veille', ['veille_id' => $veille->id]);
        // L'abonnement personnel et l'article mutualisé survivent.
        $this->assertDatabaseHas('user_sources', ['id' => $subscription->id]);
        $this->assertDatabaseHas('articles', ['id' => $article->id]);
    }

    public function test_create_rolls_back_everything_when_a_source_is_withdrawn(): void
    {
        $user = User::factory()->create();
        // M14 — source retirée du catalogue : subscribe() lève toujours
        // (seules les sources actives sont abonnables).
        $private = Source::factory()->create([
            'is_catalogue' => true, 'catalogue_state' => 'withdrawn',
        ]);

        try {
            app(VeilleService::class)->create(
                $user,
                ['name' => 'Veille X', 'is_active' => true, 'alerts_enabled' => false],
                ['laravel'],
                [$private->id],
            );
            $this->fail('RuntimeException attendue');
        } catch (\RuntimeException) {
        }

        // Rollback complet : ni veille, ni mot-clé, ni pivot, ni abonnement.
        $this->assertSame(0, Veille::count());
        $this->assertSame(0, VeilleKeyword::count());
        $this->assertSame(0, DB::table('source_veille')->count());
        $this->assertSame(0, UserSource::count());
    }
}

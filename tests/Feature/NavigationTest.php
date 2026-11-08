<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Bandeau de navigation : rubriques, état actif, navigation textuelle. */
class NavigationTest extends TestCase
{
    use RefreshDatabase;

    private const ROUTES = [
        'dashboard', 'veilles.index', 'favorites.index', 'archives.index',
        'categories.index', 'exclusions.index', 'shares.index',
        'sources.index',
    ];

    private function nav(string $html): string
    {
        preg_match('#<nav x-data.*?</nav>#s', $html, $m);

        return $m[0] ?? '';
    }

    public function test_toutes_les_rubriques_en_bureau_et_mobile(): void
    {
        $user = User::factory()->create();
        $nav = $this->nav($this->actingAs($user)->get('/dashboard')->assertOk()->getContent());

        foreach (self::ROUTES as $name) {
            // Une fois dans le bandeau, une fois dans le menu étroit (+ logo pour le dashboard).
            $expected = $name === 'dashboard' ? 3 : 2;
            $this->assertSame($expected, substr_count($nav, 'href="'.route($name).'"'), $name);
        }
        $this->assertStringContainsString($user->name, $nav);
        $this->assertStringContainsString(route('profile.edit'), $nav);
        $this->assertStringContainsString(route('logout'), $nav);
    }

    public function test_rubrique_active_mise_en_evidence(): void
    {
        $nav = $this->nav($this->actingAs(User::factory()->create())->get('/exclusions')->assertOk()->getContent());

        $this->assertSame(2, substr_count($nav, 'aria-current="page"'));
        $this->assertMatchesRegularExpression(
            '#href="'.preg_quote(route('exclusions.index'), '#').'"[^>]*aria-current="page"#',
            $nav,
        );
    }

    public function test_navigation_textuelle_sans_icone(): void
    {
        $nav = $this->nav($this->actingAs(User::factory()->create())->get('/dashboard')->getContent());

        // Seule la petite flèche du menu compte subsiste ; le logo de
        // marque est la seule image ; le menu étroit est un bouton texte.
        $this->assertSame(1, substr_count($nav, '<svg'));
        $this->assertSame(1, substr_count($nav, '<img'));
        $this->assertStringContainsString('logo.svg', $nav);
        $this->assertStringContainsString('Menu', $nav);
    }
}

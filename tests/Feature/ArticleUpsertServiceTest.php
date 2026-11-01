<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Source;
use App\Services\Feed\ArticleUpsertService;
use App\Services\Feed\ArticleUrlNormalizer;
use App\Services\Feed\Data\FeedItemData;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class ArticleUpsertServiceTest extends TestCase
{
    use RefreshDatabase;

    private ArticleUpsertService $service;

    private Source $source;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ArticleUpsertService(new ArticleUrlNormalizer);
        $this->source = Source::factory()->create();
    }

    private function item(array $overrides = []): FeedItemData
    {
        $get = static fn (string $key, mixed $default = null): mixed => array_key_exists($key, $overrides)
            ? $overrides[$key]
            : $default;

        return new FeedItemData(
            guid: $get('guid', 'guid-1'),
            guidIsPermalink: $get('guidIsPermalink', true),
            url: $get('url', 'https://exemple.fr/a1'),
            title: $get('title', 'Titre'),
            author: $get('author', 'Auteur'),
            summary: $get('summary', 'Résumé'),
            content: $get('content', '<p>Contenu</p>'),
            publishedAt: $get('publishedAt'),
            updatedAt: $get('updatedAt'),
        );
    }

    // ---------- Création / clés ----------

    public function test_creates_article_with_all_fields(): void
    {
        $published = CarbonImmutable::parse('2026-10-30 08:00:00', 'UTC');
        $r = $this->service->upsertBatch($this->source, [
            $this->item(['guid' => 'g-abc', 'publishedAt' => $published]),
        ]);

        $this->assertSame(1, $r->received);
        $this->assertSame(1, $r->created);
        $this->assertSame(0, $r->updated + $r->unchanged + $r->skipped + $r->collisions);

        $a = Article::sole();
        $this->assertSame($this->source->id, $a->source_id);
        $this->assertSame('g-abc', $a->guid);
        $this->assertSame(hash('sha256', 'g-abc'), $a->dedup_key);
        $this->assertSame('https://exemple.fr/a1', $a->url);
        $this->assertSame(hash('sha256', 'https://exemple.fr/a1'), $a->url_hash);
        $this->assertSame(ArticleUpsertService::contentHash($this->item(['guid' => 'g-abc'])), $a->content_hash);
        $this->assertSame('Titre', $a->title);
        $this->assertNotEmpty(Carbon::parse($a->collected_at)->format('Y-m-d'));
        $this->assertEquals('2026-10-30 08:00:00', Carbon::parse($a->published_at)->format('Y-m-d H:i:s'));
    }

    public function test_url_is_stored_raw_but_hashed_normalized(): void
    {
        $this->service->upsertBatch($this->source, [
            $this->item(['url' => 'HTTPS://WWW.Exemple.FR/a?utm_source=x#frag']),
        ]);

        $a = Article::sole();
        // URL originale conservée dans la colonne.
        $this->assertSame('HTTPS://WWW.Exemple.FR/a?utm_source=x#frag', $a->url);
        // url_hash calculée sur la forme normalisée (B.2).
        $this->assertSame(hash('sha256', 'https://exemple.fr/a'), $a->url_hash);
    }

    public function test_non_permalink_guid_does_not_drive_dedup_key(): void
    {
        $this->service->upsertBatch($this->source, [
            $this->item(['guid' => 'opaque-1', 'guidIsPermalink' => false]),
        ]);

        $a = Article::sole();
        $this->assertSame('opaque-1', $a->guid);
        $this->assertSame(hash('sha256', 'https://exemple.fr/a1'), $a->dedup_key);
    }

    // ---------- Doublon exact / mise à jour ----------

    public function test_identical_item_is_unchanged_not_duplicated(): void
    {
        $item = $this->item();
        $this->service->upsertBatch($this->source, [$item]);
        $r = $this->service->upsertBatch($this->source, [$item]);

        $this->assertSame(1, $r->unchanged);
        $this->assertSame(1, Article::count());
    }

    public function test_content_change_updates_same_row(): void
    {
        $this->service->upsertBatch($this->source, [$this->item()]);
        $before = Article::sole();

        $r = $this->service->upsertBatch($this->source, [
            $this->item(['summary' => 'Résumé modifié']),
        ]);

        $after = Article::sole();
        $this->assertSame(1, $r->updated);
        $this->assertSame($before->id, $after->id);
        $this->assertSame('Résumé modifié', $after->summary);
        // collected_at préservé (§4.B), content_hash rafraîchi.
        $this->assertTrue(
            Carbon::parse($before->collected_at)
                ->equalTo(Carbon::parse($after->collected_at)),
        );
        $this->assertNotSame($before->content_hash, $after->content_hash);
    }

    public function test_mutable_fields_update_but_published_at_is_preserved(): void
    {
        $p1 = CarbonImmutable::parse('2026-10-01 10:00:00', 'UTC');
        $this->service->upsertBatch($this->source, [$this->item(['publishedAt' => $p1])]);

        $r = $this->service->upsertBatch($this->source, [
            $this->item([
                'title' => 'T2', 'author' => 'A2', 'summary' => 'S2', 'content' => 'C2',
                'publishedAt' => CarbonImmutable::parse('2026-10-05 10:00:00', 'UTC'),
            ]),
        ]);

        $a = Article::sole();
        $this->assertSame(1, $r->updated);
        $this->assertSame('T2', $a->title);
        $this->assertSame('A2', $a->author);
        // published_at n'est pas dans la liste des champs mutables (§4.B).
        $this->assertEquals('2026-10-01 10:00:00', Carbon::parse($a->published_at)->format('Y-m-d H:i:s'));
    }

    public function test_url_change_with_stable_guid_updates_url_and_hash(): void
    {
        $this->service->upsertBatch($this->source, [$this->item()]);

        // Même GUID exploitable, URL différente, contenu identique :
        // dedup_key issue du GUID → update simple url/url_hash (§4.B).
        $r = $this->service->upsertBatch($this->source, [
            $this->item(['url' => 'https://exemple.fr/a1-v2']),
        ]);

        $a = Article::sole();
        $this->assertSame(1, $r->updated);
        $this->assertSame('https://exemple.fr/a1-v2', $a->url);
        $this->assertSame(hash('sha256', 'https://exemple.fr/a1-v2'), $a->url_hash);
        $this->assertSame(hash('sha256', 'guid-1'), $a->dedup_key);
    }

    public function test_same_guid_different_url_is_one_article_not_two(): void
    {
        // GUID identique exploitable = même identité déclarée : la
        // seconde URL est un changement d'URL, pas un nouvel article.
        $this->service->upsertBatch($this->source, [
            $this->item(['url' => 'https://exemple.fr/u1']),
        ]);
        $r = $this->service->upsertBatch($this->source, [
            $this->item(['url' => 'https://autre.fr/u2', 'content' => 'C2']),
        ]);

        $this->assertSame(1, Article::count());
        $this->assertSame(1, $r->updated);
    }

    // ---------- GUID changé / secours url_hash ----------

    public function test_guid_change_updates_row_via_url_hash(): void
    {
        $this->service->upsertBatch($this->source, [$this->item(['guid' => 'g-v1'])]);

        // GUID exploitable différent + même URL : url_hash unique →
        // cette ligne est l'article, guid/dedup_key/url mis à jour.
        $r = $this->service->upsertBatch($this->source, [
            $this->item(['guid' => 'g-v2']),
        ]);

        $a = Article::sole();
        $this->assertSame(1, $r->updated);
        $this->assertSame('g-v2', $a->guid);
        $this->assertSame(hash('sha256', 'g-v2'), $a->dedup_key);
        $this->assertSame(1, Article::count());
    }

    public function test_url_derived_row_promotes_to_guid_key_when_guid_appears(): void
    {
        // Item sans GUID exploitable → clé dérivée de l'URL.
        $this->service->upsertBatch($this->source, [
            $this->item(['guid' => null, 'guidIsPermalink' => false]),
        ]);
        $this->assertSame(hash('sha256', 'https://exemple.fr/a1'), Article::sole()->dedup_key);

        // Le flux déclare ensuite un GUID permalien : url_hash → la
        // ligne est retrouvée, guid et dedup_key promus.
        $r = $this->service->upsertBatch($this->source, [
            $this->item(['guid' => 'g-now', 'guidIsPermalink' => true]),
        ]);

        $a = Article::sole();
        $this->assertSame(1, $r->updated);
        $this->assertSame('g-now', $a->guid);
        $this->assertSame(hash('sha256', 'g-now'), $a->dedup_key);
    }

    // ---------- Items invalides / clé de repli ----------

    public function test_items_without_url_or_title_are_skipped(): void
    {
        $r = $this->service->upsertBatch($this->source, [
            $this->item(),
            $this->item(['url' => null]),
            $this->item(['url' => '   ']),
            $this->item(['title' => null]),
            $this->item(['title' => '  ']),
            $this->item(['url' => 'https://exemple.fr/'.str_repeat('x', 2050)]),
        ]);

        $this->assertSame(6, $r->received);
        $this->assertSame(1, $r->created);
        $this->assertSame(5, $r->skipped);
        $this->assertSame(1, Article::count());
    }

    public function test_fallback_key_dedupes_unusable_guid_and_url(): void
    {
        // GUID non exploitable + URL non normalisable → clé de repli
        // `title|published_at|summary[:120]` : deux items identiques
        // (résidu de collision documenté §8 du modèle) → même article.
        $published = CarbonImmutable::parse('2026-10-30 08:00:00', 'UTC');
        $item = fn (string $url) => $this->item([
            'guid' => 'opaque', 'guidIsPermalink' => false,
            'url' => $url, 'publishedAt' => $published,
        ]);

        $r1 = $this->service->upsertBatch($this->source, [$item('ht!tp://invalide-1')]);
        $r2 = $this->service->upsertBatch($this->source, [$item('ht!tp://invalide-2')]);

        $this->assertSame(1, $r1->created);
        // Même clé de repli → la seconde URL est un changement d'URL.
        $this->assertSame(1, $r2->updated);
        $this->assertSame(1, Article::count());
        $a = Article::sole();
        $this->assertSame(
            hash('sha256', 'Titre|2026-10-30T08:00:00Z|Résumé'),
            $a->dedup_key,
        );
    }

    // ---------- Collisions / jamais de fusion ----------

    public function test_ambiguous_url_hash_inserts_without_merging(): void
    {
        // Deux lignes distinctes partagent déjà url_hash (état hérité) :
        // correspondance ambiguë → insertion distincte, jamais de fusion.
        $urlHash = hash('sha256', 'https://exemple.fr/a1');
        Article::factory()->create([
            'source_id' => $this->source->id,
            'url' => 'https://exemple.fr/a1', 'url_hash' => $urlHash,
            'dedup_key' => hash('sha256', 'cle-a'), 'guid' => 'ga',
        ]);
        Article::factory()->create([
            'source_id' => $this->source->id,
            'url' => 'https://exemple.fr/a1', 'url_hash' => $urlHash,
            'dedup_key' => hash('sha256', 'cle-b'), 'guid' => 'gb',
        ]);
        Log::shouldReceive('error')->once();

        $r = $this->service->upsertBatch($this->source, [
            $this->item(['guid' => 'g-new']),
        ]);

        $this->assertSame(1, $r->created);
        $this->assertSame(1, $r->collisions);
        $this->assertSame(3, Article::count());
        // La nouvelle ligne a sa propre clé.
        $this->assertSame(
            hash('sha256', 'g-new'),
            Article::where('guid', 'g-new')->sole()->dedup_key,
        );
    }

    public function test_two_rows_can_share_url_hash_when_url_changed(): void
    {
        // Article 1 : URL déplacée de u1 → u2 (clé issue du GUID).
        $this->service->upsertBatch($this->source, [$this->item(['guid' => 'g1', 'url' => 'https://exemple.fr/u1'])]);
        $this->service->upsertBatch($this->source, [$this->item(['guid' => 'g1', 'url' => 'https://exemple.fr/u2'])]);

        // Article 2 arrive ensuite sur u1 : nouvelle ligne distincte.
        $r = $this->service->upsertBatch($this->source, [
            $this->item(['guid' => 'g2', 'url' => 'https://exemple.fr/u1']),
        ]);

        $this->assertSame(1, $r->created);
        $this->assertSame(2, Article::count());
        $this->assertSame('https://exemple.fr/u2', Article::where('guid', 'g1')->sole()->url);
    }

    public function test_two_items_same_key_in_one_batch(): void
    {
        // Deux items identiques dans le même lot : le second est un
        // doublon exact — pas de doublon créé.
        $r = $this->service->upsertBatch($this->source, [
            $this->item(),
            $this->item(),
        ]);

        $this->assertSame(1, $r->created);
        $this->assertSame(1, $r->unchanged);
        $this->assertSame(1, Article::count());
    }

    // ---------- content_hash (P8) ----------

    public function test_content_hash_is_deterministic_and_field_scoped(): void
    {
        $base = $this->item();
        $hash = ArticleUpsertService::contentHash($base);

        $this->assertSame($hash, ArticleUpsertService::contentHash($this->item()));
        $this->assertNotSame($hash, ArticleUpsertService::contentHash($this->item(['title' => 'X'])));
        $this->assertNotSame($hash, ArticleUpsertService::contentHash($this->item(['author' => 'X'])));
        $this->assertNotSame($hash, ArticleUpsertService::contentHash($this->item(['summary' => 'X'])));
        $this->assertNotSame($hash, ArticleUpsertService::contentHash($this->item(['content' => 'X'])));
        // Champs volatils exclus (P8) : url, guid, published_at.
        $this->assertSame($hash, ArticleUpsertService::contentHash($this->item(['url' => 'https://x.fr/1'])));
        $this->assertSame($hash, ArticleUpsertService::contentHash($this->item(['guid' => 'autre'])));
        $this->assertSame($hash, ArticleUpsertService::contentHash($this->item(['publishedAt' => CarbonImmutable::now('UTC')])));
    }

    public function test_content_hash_null_and_empty_equivalence(): void
    {
        $this->assertSame(
            ArticleUpsertService::contentHash($this->item(['author' => null, 'summary' => null, 'content' => null])),
            ArticleUpsertService::contentHash($this->item(['author' => '', 'summary' => '', 'content' => ''])),
        );
        // Formule P8 gelée : 4 champs trimmés joints par \x1f.
        $expected = hash('sha256', implode("\x1f", ['Titre', 'Auteur', 'Résumé', '<p>Contenu</p>']));
        $this->assertSame($expected, ArticleUpsertService::contentHash($this->item()));
    }

    // ---------- Atomicité du lot (P9) ----------

    public function test_invalid_item_does_not_fail_the_batch(): void
    {
        $r = $this->service->upsertBatch($this->source, [
            $this->item(['url' => 'https://exemple.fr/a1']),
            $this->item(['url' => null]),
            $this->item(['guid' => 'g2', 'url' => 'https://exemple.fr/a2']),
        ]);

        $this->assertSame(2, $r->created);
        $this->assertSame(1, $r->skipped);
        $this->assertSame(2, Article::count());
    }

    public function test_systemic_error_rolls_back_the_whole_batch(): void
    {
        // Erreur systémique simulée au 2ᵉ item : le 1er a déjà été
        // inséré dans la transaction — tout doit être annulé.
        Article::saving(function (Article $article): void {
            if ($article->title === 'Erreur forcée') {
                throw new \RuntimeException('forced systemic failure');
            }
        });

        try {
            $this->service->upsertBatch($this->source, [
                $this->item(['url' => 'https://exemple.fr/a1']),
                $this->item(['url' => 'https://exemple.fr/a2', 'title' => 'Erreur forcée']),
            ]);
            $this->fail('RuntimeException attendue');
        } catch (\RuntimeException $e) {
            $this->assertSame('forced systemic failure', $e->getMessage());
        }

        $this->assertSame(0, Article::count());
    }

    public function test_counters_are_consistent(): void
    {
        $this->service->upsertBatch($this->source, [$this->item()]);
        $r = $this->service->upsertBatch($this->source, [
            $this->item(),                                      // unchanged
            $this->item(['summary' => 'modifié']),              // updated
            $this->item(['guid' => 'g2', 'url' => 'https://exemple.fr/a2']), // created
            $this->item(['url' => null]),                       // skipped
        ]);

        $this->assertSame(4, $r->received);
        $this->assertSame(1, $r->unchanged);
        $this->assertSame(1, $r->updated);
        $this->assertSame(1, $r->created);
        $this->assertSame(1, $r->skipped);
        $this->assertSame(2, Article::count());
    }
}

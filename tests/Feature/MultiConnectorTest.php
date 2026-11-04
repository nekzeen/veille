<?php

namespace Tests\Feature;

use App\Jobs\FetchFeedJob;
use App\Models\Article;
use App\Models\Source;
use App\Models\User;
use App\Models\UserSource;
use App\Models\Veille;
use App\Models\VeilleKeyword;
use App\Services\Feed\ArticleUpsertService;
use App\Services\Feed\Connectors\ConnectorRegistry;
use App\Services\Feed\Connectors\HtmlConnector;
use App\Services\Feed\Connectors\JsonFeedConnector;
use App\Services\Feed\Data\FeedFetchResult;
use App\Services\Feed\Detection\SourceDetector;
use App\Services\Feed\Exceptions\FeedParseException;
use App\Services\Feed\FeedClientInterface;
use App\Services\Feed\Html\HtmlExtractor;
use App\Services\Feed\IpResolverInterface;
use App\Services\Feed\JsonFeedParser;
use App\Services\Feed\SecureFeedClient;
use App\Services\KeywordMatcher;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Psr\Http\Message\RequestInterface;
use Tests\Fakes\FakeFeedClient;
use Tests\TestCase;

/**
 * Connecteurs JSON Feed et HTML (M8.4) : extraction, intégration au
 * pipeline de collecte, détection et prévisualisation — aucune E/S
 * réseau (Guzzle simulé + DNS factice).
 */
class MultiConnectorTest extends TestCase
{
    use RefreshDatabase;

    private function fakeHttp(array $responses): void
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::tap(function (RequestInterface $request): void {}));
        $resolver = new class implements IpResolverInterface
        {
            public function resolve(string $host): array
            {
                return ['93.184.216.34'];
            }
        };

        $this->app->instance(FeedClientInterface::class, new SecureFeedClient($resolver, new Client(['handler' => $stack])));
    }

    private static function fixture(string $path): string
    {
        return (string) file_get_contents(base_path('tests/fixtures/'.$path));
    }

    private static function ok(string $body, string $type): Response
    {
        return new Response(200, ['Content-Type' => $type], $body);
    }

    private function fetchResult(string $body, string $url = 'https://exemple.fr/page'): FeedFetchResult
    {
        return new FeedFetchResult($body, 200, $url, $url, null, null, false, strlen($body), 0);
    }

    private function runJob(Source $source, FeedClientInterface $client): void
    {
        $this->app->instance(FeedClientInterface::class, $client);
        (new FetchFeedJob($source->id))->handle(
            app(ConnectorRegistry::class),
            app(ArticleUpsertService::class),
            app(KeywordMatcher::class),
        );
    }

    private function source(string $connector, array $attributes = []): Source
    {
        return Source::factory()->create([
            'feed_url' => 'https://exemple.fr/'.$connector.'-'.uniqid('', true),
            'connector' => $connector,
            ...$attributes,
        ]);
    }

    // ---------- Parseur JSON Feed ----------

    public function test_json_feed_versions_1_et_11_acceptees(): void
    {
        $parser = app(JsonFeedParser::class);

        $v1 = $parser->parse(self::fixture('detection/jsonfeed.json'));
        $this->assertSame(2, count($v1->items));
        $this->assertSame('Un', $v1->items[0]->title);
        $this->assertTrue($v1->items[0]->guidIsPermalink);
        $this->assertSame('1', $v1->items[0]->guid);

        $empty = $parser->parse(self::fixture('detection/jsonfeed_empty.json'));
        $this->assertSame(0, count($empty->items));
    }

    public function test_json_feed_version_inconnue_refusee(): void
    {
        $this->expectException(FeedParseException::class);
        app(JsonFeedParser::class)->parse(self::fixture('detection/jsonfeed_v2.json'));
    }

    public function test_json_feed_champs_absents_et_invalides(): void
    {
        $parsed = app(JsonFeedParser::class)->parse(self::fixture('detection/jsonfeed_sparse.json'));

        [$a, $b, $c] = $parsed->items;
        $this->assertSame('Sans id ni date', $a->title);
        $this->assertNull($a->guid);
        $this->assertFalse($a->guidIsPermalink);
        $this->assertNull($a->publishedAt);

        $this->assertNull($b->url); // javascript: jamais une URL d'article
        $this->assertNull($b->publishedAt);

        $this->assertNull($c->title);
        $this->assertSame('Corps seul', $c->content);
        $this->assertNotEmpty($parsed->warnings);
    }

    public function test_json_feed_malforme_refuse(): void
    {
        $this->expectException(FeedParseException::class);
        app(JsonFeedParser::class)->parse('{"version": pas json');
    }

    // ---------- Extracteur HTML ----------

    public function test_jsonld_itemlist_extrait_et_dedoublonne(): void
    {
        $parsed = app(HtmlExtractor::class)->extract(
            self::fixture('detection/html_jsonld_itemlist.html'), 'https://exemple.fr/actu');

        $this->assertCount(2, $parsed->items); // doublon /a/premier ignoré
        $first = $parsed->items[0];
        $this->assertSame('Premier article ministériel', $first->title);
        $this->assertSame('https://exemple.fr/a/premier', $first->url);
        $this->assertSame('Résumé premier.', $first->summary);
        $this->assertSame('Ministère', $first->author);
        $this->assertSame('2026-10-28T07:00:00+00:00', $first->publishedAt?->toIso8601String());
        $this->assertSame('2026-10-27T00:00:00+00:00', $parsed->items[1]->publishedAt?->toIso8601String());
        $this->assertNull($parsed->items[1]->summary); // absent → jamais inventé
    }

    public function test_page_article_open_graph_un_seul_article(): void
    {
        $parsed = app(HtmlExtractor::class)->extract(
            self::fixture('detection/html_og_article.html'), 'https://exemple.fr/cp/42');

        $this->assertCount(1, $parsed->items);
        $this->assertSame('Communiqué de presse OG', $parsed->items[0]->title);
        $this->assertSame('https://exemple.fr/cp/42', $parsed->items[0]->url);
        $this->assertSame('Résumé OG du communiqué.', $parsed->items[0]->summary);
        $this->assertSame('2026-10-20T08:00:00+00:00', $parsed->items[0]->publishedAt?->toIso8601String());
    }

    public function test_liste_repetitive_sans_navigation_ni_liens_dangereux(): void
    {
        $parsed = app(HtmlExtractor::class)->extract(
            self::fixture('detection/html_repeated.html'), 'https://exemple.fr/recherche?q=x');

        $urls = array_column($parsed->items, 'url');
        $this->assertSame([
            'https://exemple.fr/doc/1',
            'https://exemple.fr/doc/2',
            'https://exemple.fr/doc/3',
        ], $urls);
        $this->assertSame('Résumé du premier document.', $parsed->items[0]->summary);
        $this->assertSame('2026-10-15T00:00:00+00:00', $parsed->items[0]->publishedAt?->toIso8601String());
        $this->assertNull($parsed->items[1]->publishedAt);
    }

    public function test_balises_article_repetees(): void
    {
        $parsed = app(HtmlExtractor::class)->extract(
            self::fixture('detection/html_articles.html'), 'https://exemple.fr/');

        $this->assertCount(2, $parsed->items);
        $this->assertSame('https://exemple.fr/d2', $parsed->items[1]->url);
        $this->assertSame('2026-10-01T10:00:00+00:00', $parsed->items[1]->publishedAt?->toIso8601String());
    }

    public function test_selecteurs_personnalises_en_dernier_recours_ou_forces(): void
    {
        $html = self::fixture('detection/html_selector_page.html');
        $extractor = app(HtmlExtractor::class);

        // Sans config : la structure répétitive suffit déjà.
        $auto = $extractor->extract($html, 'https://exemple.fr/');
        $this->assertCount(3, $auto->items);

        // Config forcée : sélecteurs seuls.
        $config = [
            'version' => 1,
            'strategy' => 'selectors',
            'selectors' => ['item' => 'div.entry', 'link' => 'a.lnk', 'date' => '.date'],
        ];
        $warnings = [];
        $strategy = null;
        $parsed = $extractor->extract($html, 'https://exemple.fr/', $config, $warnings, $strategy);

        $this->assertSame('selectors', $strategy);
        $this->assertCount(3, $parsed->items);
        $this->assertSame('https://exemple.fr/e1', $parsed->items[0]->url);
        $this->assertSame('Entrée une du portail', $parsed->items[0]->title);
        $this->assertSame('2026-09-30T00:00:00+00:00', $parsed->items[0]->publishedAt?->toIso8601String());
        $this->assertNull($parsed->items[1]->publishedAt);
    }

    public function test_page_generique_sans_article_et_html_malforme(): void
    {
        $extractor = app(HtmlExtractor::class);

        $plain = $extractor->extract(self::fixture('detection/html_plain.html'), 'https://exemple.fr/p');
        $this->assertCount(0, $plain->items);

        $malformed = $extractor->extract(self::fixture('detection/html_malformed.html'), 'https://exemple.fr/m');
        $this->assertLessThan(2, count($malformed->items));

        $captcha = $extractor->extract(self::fixture('detection/html_captcha.html'), 'https://exemple.fr/c');
        $this->assertCount(0, $captcha->items);
    }

    public function test_liens_extraits_vers_ip_privees_et_schemes_dangereux_exclus(): void
    {
        $html = '<ul><li><a href="http://10.0.0.1/secret">Article vers intranet privé</a></li>'
            .'<li><a href="javascript:track()">Lien javascript déguisé</a></li>'
            .'<li><a href="http://192.168.1.1/admin">Lien réseau local privé</a></li>'
            .'<li><a href="/ok">Article public un</a></li>'
            .'<li><a href="/ok2">Article public deux</a></li>'
            .'<li><a href="/ok3">Article public trois</a></li></ul>';

        $parsed = app(HtmlExtractor::class)->extract($html, 'https://exemple.fr/');

        $this->assertCount(3, $parsed->items);
        foreach ($parsed->items as $item) {
            $this->assertMatchesRegularExpression('#^https://exemple\.fr#', (string) $item->url);
        }
    }

    // ---------- Collecte (pipeline complet) ----------

    public function test_collecte_json_feed_persiste_et_associe_aux_veilles(): void
    {
        $source = $this->source('json_feed');
        $user = User::factory()->create();
        UserSource::firstOrCreate(['user_id' => $user->id, 'source_id' => $source->id], ['created_at' => now('UTC')]);
        $veille = Veille::factory()->for($user)->create();
        VeilleKeyword::factory()->for($veille)->create(['term' => 'deux']);
        $veille->sources()->attach($source->id);

        $this->runJob($source, new FakeFeedClient($this->fetchResult(self::fixture('detection/jsonfeed.json'))));

        $this->assertSame(2, Article::where('source_id', $source->id)->count());
        $this->assertSame(1, DB::table('article_veille')->where('veille_id', $veille->id)->count());
        $this->assertSame(0, $source->fresh()->fail_count);
    }

    public function test_collecte_html_extrait_et_associe_aux_veilles(): void
    {
        $source = $this->source('html');
        $user = User::factory()->create();
        UserSource::firstOrCreate(['user_id' => $user->id, 'source_id' => $source->id], ['created_at' => now('UTC')]);
        $veille = Veille::factory()->for($user)->create();
        VeilleKeyword::factory()->for($veille)->create(['term' => 'décret']);
        $veille->sources()->attach($source->id);

        $this->runJob($source, new FakeFeedClient($this->fetchResult(self::fixture('detection/html_repeated.html'))));

        $this->assertSame(3, Article::where('source_id', $source->id)->count());
        $matched = DB::table('article_veille')->where('veille_id', $veille->id)->count();
        $this->assertGreaterThanOrEqual(1, $matched); // « Décret… » matche
        $this->assertSame(0, $source->fresh()->fail_count);
    }

    public function test_collecte_html_enrichissement_borne_et_facultatif(): void
    {
        $source = $this->source('html', [
            'config' => ['version' => 1, 'strategy' => 'auto', 'enrich_pages' => 1],
        ]);
        $items = '<ul><li><h3><a href="/doc/1">Document un sans date ni résumé</a></h3></li>'
            .'<li><h3><a href="/doc/2">Document deux sans date ni résumé</a></h3></li>'
            .'<li><h3><a href="/doc/3">Document trois sans date ni résumé</a></h3></li></ul>';
        $page1 = self::fixture('detection/html_article_page.html');
        $articleUrl = 'https://exemple.fr/doc/1';
        $client = new class($items, $articleUrl, $page1) implements FeedClientInterface
        {
            public array $calls = [];

            public function __construct(private string $list, private string $articleUrl, private string $articlePage) {}

            public function fetch(string $url, ?string $etag = null, ?string $lastModified = null): FeedFetchResult
            {
                $this->calls[] = $url;
                $body = $url === $this->articleUrl ? $this->articlePage : $this->list;

                return new FeedFetchResult($body, 200, $url, $url, null, null, false, strlen($body), 0);
            }
        };

        $this->runJob($source, $client);

        $this->assertSame([$source->feed_url, $articleUrl], $client->calls); // 1 + au plus enrich_pages
        $article = Article::where('source_id', $source->id)->where('url', 'like', '%doc/1')->first();
        $this->assertSame('Résumé complet.', $article->summary);
        $this->assertStringStartsWith('2026-10-19 12:00:00', (string) $article->published_at);
        // Les deux autres articles restent sans enrichissement.
        $this->assertNull(Article::where('source_id', $source->id)->where('url', 'like', '%doc/2')->value('summary'));
    }

    public function test_collecte_json_feed_304_et_erreur(): void
    {
        $source = $this->source('json_feed');

        $this->runJob($source, FakeFeedClient::notModified());
        $this->assertSame(0, Article::where('source_id', $source->id)->count());
        $this->assertSame(0, $source->fresh()->fail_count);

        $source = $this->source('json_feed');
        $this->runJob($source, new FakeFeedClient(new FeedParseException('JSON illisible.')));
        $this->assertSame(1, $source->fresh()->fail_count);
        $this->assertNotNull($source->fresh()->last_error);
    }

    public function test_connecteurs_resolus_par_le_registre(): void
    {
        $registry = app(ConnectorRegistry::class);

        $this->assertInstanceOf(JsonFeedConnector::class, $registry->for($this->source('json_feed')));
        $this->assertInstanceOf(HtmlConnector::class, $registry->for($this->source('html')));
        $this->assertSame(0, DB::table('sources')->count() - 2);
    }

    // ---------- Détection et prévisualisation ----------

    public function test_detection_jsonld_et_previsualisation(): void
    {
        $this->fakeHttp([
            self::ok(self::fixture('detection/html_jsonld_itemlist.html'), 'text/html'),
            self::ok(self::fixture('detection/html_jsonld_itemlist.html'), 'text/html'),
        ]);

        $detect = app(SourceDetector::class)->detect('https://exemple.fr/actu')->toArray();
        $this->assertSame('html', $detect['connector']);
        $this->assertSame('jsonld', $detect['details']['extraction']['strategy']);
        $this->assertSame(2, $detect['details']['extraction']['items']);
        $this->assertSame('medium', $detect['confidence']);

        $json = $this->actingAs(User::factory()->create())
            ->postJson(route('sources.preview'), ['url' => 'https://exemple.fr/actu'])
            ->assertOk()
            ->assertJsonPath('preview.status', 'ok')
            ->json();

        $this->assertCount(2, $json['preview']['items']);
        $this->assertSame('Premier article ministériel', $json['preview']['items'][0]['title']);
        $this->assertSame([], $json['preview']['items'][0]['missing']);
        $this->assertSame(['summary'], $json['preview']['items'][1]['missing']);
    }

    public function test_previsualisation_json_feed(): void
    {
        $this->fakeHttp([self::ok(self::fixture('detection/jsonfeed.json'), 'application/feed+json')]);

        $this->actingAs(User::factory()->create())
            ->postJson(route('sources.preview'), ['url' => 'https://exemple.fr/feed.json'])
            ->assertOk()
            ->assertJsonPath('status', 'detected')
            ->assertJsonPath('connector', 'json_feed')
            ->assertJsonPath('preview.status', 'ok')
            ->assertJsonPath('preview.items.0.title', 'Un');
    }

    public function test_detection_et_previsualisation_sans_persistance(): void
    {
        $this->fakeHttp([
            self::ok(self::fixture('detection/html_repeated.html'), 'text/html'),
            self::ok(self::fixture('detection/html_repeated.html'), 'text/html'),
        ]);
        $tables = ['sources', 'user_sources', 'articles', 'article_veille', 'article_user'];
        $before = array_map(fn (string $t): int => DB::table($t)->count(), $tables);
        $user = User::factory()->create();

        $this->actingAs($user)->postJson(route('sources.detect'), ['url' => 'https://exemple.fr/recherche'])->assertOk();
        $this->actingAs($user)->postJson(route('sources.preview'), ['url' => 'https://exemple.fr/recherche'])->assertOk();

        $this->assertSame($before, array_map(fn (string $t): int => DB::table($t)->count(), $tables));
    }

    public function test_javascript_de_page_jamais_execute(): void
    {
        // Un script qui « créerait » des articles à l'exécution : la
        // détection et l'extraction ne l'évaluent jamais — 0 article.
        $html = '<html><body><script>document.write("<ul><li><a href=x>fake article long text</a>");</script>'
            .'<ul><li><a href="/a1">Vrai article numéro un</a></li>'
            .'<li><a href="/a2">Vrai article numéro deux</a></li>'
            .'<li><a href="/a3">Vrai article numéro trois</a></li></ul></body></html>';
        $this->fakeHttp([self::ok($html, 'text/html')]);

        $detect = app(SourceDetector::class)->detect('https://exemple.fr/p')->toArray();

        $this->assertSame(3, $detect['details']['extraction']['items']);
    }

    // ---------- Régression RSS ----------

    public function test_collecte_rss_inchangee(): void
    {
        $source = $this->source('rss');
        $veille = Veille::factory()->create();
        VeilleKeyword::factory()->for($veille)->create(['term' => 'test']);
        $veille->sources()->attach($source->id);

        $this->runJob($source, new FakeFeedClient($this->fetchResult(self::fixture('feeds/rss20_basic.xml'))));

        $this->assertSame(2, Article::where('source_id', $source->id)->count());
        $this->assertSame(0, $source->fresh()->fail_count);
    }
}

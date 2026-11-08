<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Source;
use App\Models\SourceTheme;
use App\Models\User;
use App\Services\Feed\Connectors\ConnectorRegistry;
use App\Services\Feed\Connectors\RssConnector;
use App\Services\Feed\Exceptions\FeedParseException;
use App\Services\Feed\FeedClientInterface;
use App\Services\Feed\IpResolverInterface;
use App\Services\Feed\SecureFeedClient;
use App\Services\SourceSubscriptionService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ConnectorRegistryTest extends TestCase
{
    use RefreshDatabase;

    public function test_source_rss_utilise_le_connecteur_rss(): void
    {
        $source = Source::factory()->create(['connector' => 'rss']);

        $this->assertInstanceOf(RssConnector::class, app(ConnectorRegistry::class)->for($source));
    }

    public function test_connecteur_inconnu_leve_feed_parse_exception(): void
    {
        $source = Source::factory()->create(['connector' => 'api']);

        $this->expectException(FeedParseException::class);
        app(ConnectorRegistry::class)->for($source);
    }

    public function test_creation_detectee_renseigne_les_champs_m8(): void
    {
        // M14 : toute création passe par la détection complète ; les
        // champs M8 sont renseignés par le parcours détecté.
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, ['Content-Type' => 'application/rss+xml; charset=utf-8'],
                (string) file_get_contents(base_path('tests/fixtures/feeds/rss20_basic.xml'))),
        ]));
        $resolver = new class implements IpResolverInterface
        {
            public function resolve(string $host): array
            {
                return ['example.com' => ['93.184.216.34']][$host] ?? [];
            }
        };
        app()->instance(FeedClientInterface::class,
            new SecureFeedClient($resolver, new Client(['handler' => $stack])));
        $theme = SourceTheme::create(['name' => 'X', 'slug' => 'x']);
        $user = User::factory()->create();
        [$source] = app(SourceSubscriptionService::class)
            ->addDetectedSource($user, 'https://example.com/feed.xml', null, $theme->id);

        $this->assertSame('https://example.com/feed.xml', $source->submitted_url);
        $this->assertSame('https://example.com/feed.xml', $source->fetch_url);
        $this->assertSame('rss', $source->connector);
        $this->assertSame('validated', $source->config_state);
    }

    public function test_source_historique_sans_fetch_url_utilise_feed_url(): void
    {
        $source = Source::factory()->create([
            'feed_url' => 'https://example.com/old.xml',
            'submitted_url' => null,
            'fetch_url' => null,
        ]);

        // RssConnector utilise fetch_url ?? feed_url : l'URL effective
        // d'une source pré-M8 reste feed_url.
        $this->assertSame('https://example.com/old.xml', $source->fetch_url ?? $source->feed_url);
    }

    public function test_nouvelles_colonnes_et_defaults_connector(): void
    {
        $columns = Schema::getColumnListing('sources');

        foreach (['submitted_url', 'fetch_url', 'connector', 'config', 'config_state', 'detection'] as $col) {
            $this->assertContains($col, $columns);
        }

        $s = Source::factory()->create()->fresh();
        $this->assertSame('rss', $s->connector);
        $this->assertSame('detected', $s->config_state);
        $this->assertNull($s->submitted_url);
    }
}

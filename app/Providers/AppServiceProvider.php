<?php

namespace App\Providers;

use App\Services\Feed\FeedClientInterface;
use App\Services\Feed\FeedParserInterface;
use App\Services\Feed\SecureFeedClient;
use App\Services\Feed\SimplePieFeedParser;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    public array $bindings = [
        FeedClientInterface::class => SecureFeedClient::class,
        FeedParserInterface::class => SimplePieFeedParser::class,
    ];

    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Détection/prévisualisation (M8.3) : chaque appel déclenche
        // jusqu'à 3 requêtes sortantes — bornage par utilisateur et par
        // hôte cible (anti-abus, politesse envers les sites).
        RateLimiter::for('source-detection', static function (Request $request): array {
            $host = strtolower((string) parse_url((string) $request->input('url'), PHP_URL_HOST));

            return [
                Limit::perMinute(10)->by('detect-user:'.$request->user()?->id),
                Limit::perMinute(20)->by('detect-host:'.$host),
            ];
        });
    }
}

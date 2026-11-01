<?php

namespace App\Providers;

use App\Services\Feed\FeedClientInterface;
use App\Services\Feed\FeedParserInterface;
use App\Services\Feed\SecureFeedClient;
use App\Services\Feed\SimplePieFeedParser;
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
        //
    }
}

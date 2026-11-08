<?php

namespace App\Providers;

use App\Services\Feed\FeedClientInterface;
use App\Services\Feed\FeedParserInterface;
use App\Services\Feed\SecureFeedClient;
use App\Services\Feed\SimplePieFeedParser;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Notifications\Messages\MailMessage;
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
        // M12.19 — e-mails transactionnels en français : les URLs
        // signées, jetons et expirations Laravel sont conservés
        // intégralement ; seul le texte est localisé.
        VerifyEmail::toMailUsing(fn ($user, string $url): MailMessage => (new MailMessage)
            ->subject('Confirmez votre adresse e-mail – Veille')
            ->greeting('Bienvenue sur Veille')
            ->line('Pour finaliser votre inscription, veuillez confirmer votre adresse e-mail en cliquant sur le bouton ci-dessous.')
            ->action('Confirmer mon adresse e-mail', $url)
            ->line('Ce lien expire dans '.config('auth.verification.expire', 60).' minutes.')
            ->line('Si vous n’avez pas créé de compte, ignorez cet e-mail.'));

        ResetPassword::toMailUsing(fn ($user, string $token): MailMessage => (new MailMessage)
            ->subject('Réinitialisez votre mot de passe – Veille')
            ->greeting('Bonjour')
            ->line('Vous avez demandé la réinitialisation du mot de passe de votre compte Veille.')
            ->action('Réinitialiser mon mot de passe', route('password.reset', ['token' => $token, 'email' => $user->getEmailForPasswordReset()]))
            ->line('Ce lien expire dans '.config('auth.passwords.users.expire', 60).' minutes.')
            ->line('Si vous n’avez pas fait cette demande, ignorez cet e-mail : votre mot de passe reste inchangé.'));

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

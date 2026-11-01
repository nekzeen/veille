<?php

namespace App\Services\Feed;

use App\Services\Feed\Data\FeedFetchResult;
use App\Services\Feed\Data\ValidatedFeedUrl;
use App\Services\Feed\Exceptions\FeedDnsException;
use App\Services\Feed\Exceptions\FeedFetchException;
use App\Services\Feed\Exceptions\FeedHttpException;
use App\Services\Feed\Exceptions\FeedNetworkException;
use App\Services\Feed\Exceptions\FeedRedirectException;
use App\Services\Feed\Exceptions\FeedTimeoutException;
use App\Services\Feed\Exceptions\FeedTlsException;
use App\Services\Feed\Exceptions\FeedTooLargeException;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Psr\Http\Message\ResponseInterface;

/**
 * Client HTTP durci anti-SSRF pour la récupération de flux.
 *
 * Pipeline par tentative : validation d'URL → résolution DNS explicite
 * → allowlist IP globale (rejet strict des réponses mixtes) →
 * connexion épinglée sur l'IP validée via CURLOPT_RESOLVE → lecture
 * bornée du corps. Redirections manuelles (≤ 3), chaque cible repasse
 * par le pipeline complet. Aucun proxy d'environnement, TLS vérifié,
 * `Accept-Encoding: identity`, User-Agent `VeilleBot/1.0`.
 */
final class SecureFeedClient implements FeedClientInterface
{
    private const MAX_REDIRECTS = 3;

    private const MAX_BYTES = 5_242_880; // 5 Mio

    private const CONNECT_TIMEOUT = 5;

    private const TOTAL_TIMEOUT = 15;

    private const LOW_SPEED_LIMIT = 8192; // 8 Ko/s

    private const LOW_SPEED_TIME = 10;

    private const REDIRECT_CODES = [301, 302, 303, 307, 308];

    private const READ_CHUNK = 65_536;

    private readonly IpResolverInterface $resolver;

    private readonly ClientInterface $http;

    private readonly FeedUrlValidator $validator;

    private readonly IpAddressPolicy $policy;

    public function __construct(
        ?IpResolverInterface $resolver = null,
        ?ClientInterface $http = null,
    ) {
        $this->resolver = $resolver ?? new DnsResolver;
        $this->http = $http ?? new Client;
        $this->validator = new FeedUrlValidator;
        $this->policy = new IpAddressPolicy;
    }

    public function fetch(string $url, ?string $etag = null, ?string $lastModified = null): FeedFetchResult
    {
        $current = $url;
        $redirects = 0;

        while (true) {
            $validated = $this->validator->validate($current);
            $ips = $this->resolveGlobal($validated->host);
            $response = $this->pinnedRequest($validated, $ips, $etag, $lastModified);
            $status = $response->getStatusCode();

            if ($status === 304) {
                $response->getBody()->close();

                return new FeedFetchResult(
                    body: '',
                    statusCode: 304,
                    url: $url,
                    effectiveUrl: $validated->uri,
                    etag: self::headerValue($response, 'ETag'),
                    lastModified: self::headerValue($response, 'Last-Modified'),
                    notModified: true,
                    bytesRead: 0,
                    redirects: $redirects,
                );
            }

            if (in_array($status, self::REDIRECT_CODES, true)) {
                $location = trim($response->getHeaderLine('Location'));
                $response->getBody()->close();

                if ($location === '') {
                    throw new FeedRedirectException("Redirection {$status} sans en-tête Location.");
                }
                if (++$redirects > self::MAX_REDIRECTS) {
                    throw new FeedRedirectException('Chaîne de plus de '.self::MAX_REDIRECTS.' redirections.');
                }

                // Revalidation complète au prochain tour de boucle.
                $current = (string) UriResolver::resolve(new Uri($validated->uri), new Uri($location));

                continue;
            }

            // Seuls 2xx, 304 et les redirections gérées sont acceptés ;
            // tout autre code (1xx, 300, 305, 306, 4xx, 5xx) est une
            // erreur — jamais de corps exploité.
            if ($status < 200 || $status >= 300) {
                $response->getBody()->close();

                throw new FeedHttpException($status);
            }

            $body = $this->readBody($response);

            return new FeedFetchResult(
                body: $body,
                statusCode: $status,
                url: $url,
                effectiveUrl: $validated->uri,
                etag: self::headerValue($response, 'ETag'),
                lastModified: self::headerValue($response, 'Last-Modified'),
                notModified: false,
                bytesRead: strlen($body),
                redirects: $redirects,
            );
        }
    }

    /**
     * DNS explicite → toutes les IP doivent être globalement routables
     * (rejet strict des réponses mixtes, P3). Un hôte IP-littéral ne
     * passe pas par le résolveur mais subit la même politique.
     *
     * @return non-empty-list<string>
     */
    private function resolveGlobal(string $host): array
    {
        $ips = filter_var($host, FILTER_VALIDATE_IP) !== false
            ? [$host]
            : array_values(array_unique($this->resolver->resolve($host)));

        if ($ips === []) {
            throw new FeedDnsException("Aucune adresse IP retournée pour {$host}.");
        }

        foreach ($ips as $ip) {
            if (! $this->policy->isGloballyRoutable($ip)) {
                throw new FeedDnsException("Adresse non globalement routable rejetée pour {$host} : {$ip}.");
            }
        }

        return $ips;
    }

    /**
     * Requête GET épinglée sur une IP validée. Les IP retournées sont
     * essayées dans l'ordre en cas d'échec de connexion (failover).
     */
    private function pinnedRequest(ValidatedFeedUrl $v, array $ips, ?string $etag, ?string $lastModified): ResponseInterface
    {
        $headers = [
            'User-Agent' => 'VeilleBot/1.0',
            'Accept' => 'application/rss+xml, application/atom+xml, application/xml, text/xml, */*',
            'Accept-Encoding' => 'identity',
        ];
        if ($etag !== null) {
            $headers['If-None-Match'] = $etag;
        }
        if ($lastModified !== null) {
            $headers['If-Modified-Since'] = $lastModified;
        }

        $lastError = null;
        foreach ($ips as $ip) {
            try {
                return $this->http->request('GET', $v->uri, $this->requestOptions($v, $ip, $headers));
            } catch (ConnectException $e) {
                $lastError = $e; // échec de connexion → IP suivante
            } catch (\Throwable $e) {
                // Préserver nos propres exceptions même enveloppées par
                // Guzzle (on_headers, callbacks).
                for ($t = $e; $t !== null; $t = $t->getPrevious()) {
                    if ($t instanceof FeedFetchException) {
                        throw $t;
                    }
                }

                throw $this->mapTransportError($e);
            }
        }

        throw $this->mapTransportError($lastError ?? new FeedNetworkException('Aucune tentative effectuée.'));
    }

    /**
     * Options Guzzle/cURL de la tentative : épinglage de l'IP validée
     * (`CURLOPT_RESOLVE`, l'hostname reste dans l'URL → Host/SNI/TLS
     * corrects), timeouts actés, low-speed, TLS ≥ 1.2 vérifié, proxy
     * d'environnement neutralisé, pas de décodage implicite.
     *
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     */
    private function requestOptions(ValidatedFeedUrl $v, string $ip, array $headers): array
    {
        return [
            'headers' => $headers,
            'http_errors' => false,
            'allow_redirects' => false,
            'decode_content' => false,
            'stream' => true,
            'connect_timeout' => self::CONNECT_TIMEOUT,
            'timeout' => self::TOTAL_TIMEOUT,
            // Coupure annoncée : Content-Length > plafond → abort précoce.
            // Le handler peut invoquer ce callback avec une exception
            // (échec de connexion) : ignorer tout ce qui n'est pas une
            // réponse.
            'on_headers' => static function (mixed $response): void {
                if (! $response instanceof ResponseInterface) {
                    return;
                }
                $declared = trim($response->getHeaderLine('Content-Length'));
                if (is_numeric($declared) && (int) $declared > self::MAX_BYTES) {
                    throw new FeedTooLargeException(
                        "Content-Length déclarée ({$declared}) supérieure au plafond."
                    );
                }
            },
            // Coupure réelle : abort dès que le téléchargement dépasse
            // le plafond (retour non nul = CURLE_ABORTED_BY_CALLBACK 42).
            'progress' => static function (int $downloadTotal, int $downloaded): int {
                return $downloaded > self::MAX_BYTES ? 1 : 0;
            },
            'curl' => [
                CURLOPT_RESOLVE => ["{$v->host}:{$v->port}:{$ip}"],
                CURLOPT_PROXY => '',
                CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
                CURLOPT_TIMEOUT => self::TOTAL_TIMEOUT,
                CURLOPT_LOW_SPEED_LIMIT => self::LOW_SPEED_LIMIT,
                CURLOPT_LOW_SPEED_TIME => self::LOW_SPEED_TIME,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
            ],
        ];
    }

    /**
     * Lecture du corps plafonnée : double contrôle de la taille
     * annoncée puis comptage effectif des octets — la limite tient même
     * sans Content-Length ou avec une annonce mensongère.
     */
    private function readBody(ResponseInterface $response): string
    {
        $declared = trim($response->getHeaderLine('Content-Length'));
        if (is_numeric($declared) && (int) $declared > self::MAX_BYTES) {
            $response->getBody()->close();

            throw new FeedTooLargeException(
                "Content-Length déclarée ({$declared}) supérieure au plafond."
            );
        }

        $stream = $response->getBody();
        $body = '';

        try {
            while (! $stream->eof()) {
                $chunk = $stream->read(self::READ_CHUNK);
                if ($chunk === '') {
                    break;
                }
                $body .= $chunk;
                if (strlen($body) > self::MAX_BYTES) {
                    throw new FeedTooLargeException('Corps de réponse supérieur au plafond de 5 Mio.');
                }
            }
        } finally {
            $stream->close();
        }

        return $body;
    }

    /**
     * Traduction des erreurs cURL en taxonomie métier. Errno 42 =
     * interruption volontaire du callback `progress` au plafond ;
     * 28 = tout dépassement de délai (connexion, global, low-speed).
     */
    private function mapTransportError(\Throwable $e): FeedFetchException
    {
        $errno = $e instanceof RequestException
            ? (int) ($e->getHandlerContext()['errno'] ?? 0)
            : 0;
        if ($errno === 0 && preg_match('/curl error (\d+)/i', $e->getMessage(), $m) === 1) {
            $errno = (int) $m[1];
        }
        $message = mb_strimwidth($e->getMessage(), 0, 200, '…');

        return match (true) {
            $errno === 42 => new FeedTooLargeException('Téléchargement interrompu au plafond de 5 Mio.', 0, $e),
            $errno === 28 => new FeedTimeoutException("Délai dépassé : {$message}", 0, $e),
            in_array($errno, [35, 51, 53, 54, 58, 59, 60, 66, 77, 80, 82, 83, 84, 90, 91], true) => new FeedTlsException("Erreur TLS : {$message}", 0, $e),
            default => new FeedNetworkException("Erreur de transport : {$message}", 0, $e),
        };
    }

    private static function headerValue(ResponseInterface $response, string $name): ?string
    {
        $value = trim($response->getHeaderLine($name));

        return $value === '' ? null : $value;
    }
}

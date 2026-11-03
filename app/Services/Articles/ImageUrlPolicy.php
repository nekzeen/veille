<?php

namespace App\Services\Articles;

use App\Services\Feed\DnsResolver;
use App\Services\Feed\FeedUrlValidator;
use App\Services\Feed\IpAddressPolicy;
use App\Services\Feed\IpResolverInterface;
use Throwable;

/**
 * Politique d'admission des URL d'images distantes (conception M1.12 §6).
 *
 * Les images sont chargées **directement par le navigateur** — pas de
 * proxy, pas de téléchargement serveur. L'URL, fournie par le flux RSS,
 * est pourtant une entrée non fiable : elle est validée ici avant d'être
 * émise dans le HTML, sans que le serveur ne fasse la requête.
 *
 * Contrôles ordonnés : schéma http(s) → identifiants refusés → IP
 * littérale (avant toute conversion IDN) jugée par
 * {@see IpAddressPolicy} → nom d'hôte normalisé → formes numériques
 * ambiguës → noms locaux → pré-résolution DNS fail-closed.
 *
 * États par document : compteur d'images et mémoïsation des verdicts
 * DNS — réinitialisables via {@see reset()} quand l'instance sert
 * plusieurs documents.
 *
 * Limites résiduelles acceptées (conception §6.3–§6.4) : la
 * pré-résolution DNS ne protège pas contre le rebinding ni le
 * split-horizon (réponses distinctes serveur/navigateur) ; les
 * redirections HTTP suivies ensuite par le navigateur ne sont pas
 * contrôlées — seuls un proxy ou un téléchargement les élimineraient,
 * écartés par la décision de chargement direct.
 */
final class ImageUrlPolicy
{
    /** Nombre maximal d'éléments <img> contrôlés par document (§6.2.1). */
    private const MAX_IMAGES_PER_DOCUMENT = 50;

    /** Nombre maximal d'hôtes uniques résolus par document (§6.2.1). */
    private const MAX_UNIQUE_HOSTS_PER_DOCUMENT = 25;

    private const MAX_URL_LENGTH = 2048;

    /**
     * Suffixes de noms locaux ou à usage spécial — rejetés après
     * normalisation, avant DNS (mono-label traité séparément).
     *
     * @var list<string>
     */
    private const LOCAL_SUFFIXES = [
        '.localhost',
        '.local',
        '.lan',
        '.internal',
        '.corp',
        '.home.arpa',
        '.test',
        '.example',
        '.invalid',
        '.onion',
    ];

    private readonly IpResolverInterface $resolver;

    /**
     * Verdicts DNS mémoïsés pour la requête/le document courant
     * (hôte normalisé => admis). Compte aussi comme jauge du plafond
     * d'hôtes uniques résolus.
     *
     * @var array<string, bool>
     */
    private array $hostVerdicts = [];

    private int $imagesChecked = 0;

    public function __construct(
        private readonly IpAddressPolicy $ips = new IpAddressPolicy,
        ?IpResolverInterface $resolver = null,
    ) {
        $this->resolver = $resolver ?? new DnsResolver;
    }

    /**
     * L'URL peut-elle être émise dans un `src` chargé par le navigateur ?
     *
     * Échoue fermé dans tous les cas : URL malformée, schéma ou hôte
     * refusé, résolveur en erreur, plafonds atteints.
     */
    public function isAllowed(?string $src): bool
    {
        if (++$this->imagesChecked > self::MAX_IMAGES_PER_DOCUMENT) {
            return false;
        }

        $src = trim((string) $src);
        if ($src === '' || strlen($src) > self::MAX_URL_LENGTH
            || preg_match('/[\x00-\x20]/', $src) === 1) {
            return false;
        }

        $parts = parse_url($src);
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            return false;
        }

        // Identifiants intégrés : http://user:pass@host/, http://evil.com@10.0.0.1/.
        if (isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        // IP littérale AVANT IDN — parse_url rend l'IPv6 avec ses crochets.
        $host = trim($parts['host'], '[]');
        if ($host === '') {
            return false;
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            // IPv4, IPv6 et IPv4-mappées (normalisées dans IpAddressPolicy).
            return $this->ips->isGloballyRoutable($host);
        }

        return $this->isHostnameAllowed($host);
    }

    /**
     * Remet les compteurs à zéro — à appeler quand l'instance sert
     * plusieurs documents (plafonds « par document »).
     */
    public function reset(): void
    {
        $this->imagesChecked = 0;
        $this->hostVerdicts = [];
    }

    /**
     * Chemin nom d'hôte : normalisation puis contrôles applicatifs.
     * `idn_to_ascii` n'est appliqué qu'à un vrai nom (jamais à une IP).
     */
    private function isHostnameAllowed(string $host): bool
    {
        $host = rtrim(strtolower($host), '.');   // point final FQDN
        if ($host === '') {
            return false;
        }

        $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
        if ($ascii === false || $ascii === '') {
            return false;
        }
        $host = strtolower($ascii);

        if ($this->isAmbiguousNumericHost($host) || $this->isLocalHostname($host)) {
            return false;
        }

        return $this->resolvedHostIsPublic($host);
    }

    /**
     * 1 à 4 parties séparées par des points toutes numériques (décimal,
     * 0x hexa, 0 octal, mixtes) — la libc/le navigateur les réinterprètent
     * en IPv4 : 2130706433, 0x7f000001, 0177.0.0.1, 127.1.
     * Règle identique à {@see FeedUrlValidator}.
     */
    private function isAmbiguousNumericHost(string $host): bool
    {
        foreach (explode('.', $host) as $part) {
            if (preg_match('/^(?:0x[0-9a-f]+|[0-9]+)$/i', $part) !== 1) {
                return false;
            }
        }

        return true;
    }

    /** Mono-label ou suffixe local/à usage spécial — jamais un hôte public. */
    private function isLocalHostname(string $host): bool
    {
        if (! str_contains($host, '.')) {
            return true;   // intranet, nas, localhost…
        }
        foreach (self::LOCAL_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Pré-résolution DNS fail-closed : rejet si aucun enregistrement
     * exploitable, si une seule adresse n'est pas globalement routable,
     * ou si le résolveur lève une erreur. Verdict mémoïsé par hôte.
     */
    private function resolvedHostIsPublic(string $host): bool
    {
        if (isset($this->hostVerdicts[$host])) {
            return $this->hostVerdicts[$host];
        }
        if (count($this->hostVerdicts) >= self::MAX_UNIQUE_HOSTS_PER_DOCUMENT) {
            return false;   // plafond d'hôtes uniques — pas de mémoïsation
        }

        try {
            $ips = $this->resolver->resolve($host);
        } catch (Throwable) {
            return $this->hostVerdicts[$host] = false;
        }

        foreach ($ips as $ip) {
            if (! $this->ips->isGloballyRoutable($ip)) {
                return $this->hostVerdicts[$host] = false;
            }
        }

        return $this->hostVerdicts[$host] = $ips !== [];
    }
}

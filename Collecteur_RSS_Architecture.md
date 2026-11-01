# Collecteur RSS/Atom — Architecture technique

> Conception produite en M1.10.1 — **aucune implémentation**.
> Références : `DataModel.md` v3.4 (source de vérité métier),
> `MigrationPlan.md`, `Architecture.md`, `REPRISE_PROJET.md`.
> État du dépôt au moment de la rédaction : commit `9e47ca1` + doc de
> reprise (`c1588e4`).

Chaque section distingue ce qui est **imposé par DataModel.md v3.4**
(`D…`, §) des décisions techniques nouvelles (`P…`) — **toutes actées**
en fin de M1.10.1 (§10).

## 1. Objectif et périmètre

Moteur de collecte qui, périodiquement :

1. identifie les sources `is_active=1` dues ;
2. récupère leur flux en GET conditionnel (`ETag`/`Last-Modified`) via un
   client HTTP durci anti-SSRF ;
3. parse RSS 2.0 et Atom de manière tolérante ;
4. normalise et déduplique chaque article selon §4.B ;
5. persiste dans `articles` (mutualisé, sans droits) ;
6. met à jour les champs techniques de `sources` (`etag`,
   `last_modified`, `last_fetched_at`, `last_success_at`, `fail_count`,
   `last_error`) ;
7. isole les erreurs : une source en échec n'interrompt jamais les autres.

**Hors périmètre de M1.10** : matching mots-clés (`article_veille`),
`article_user`, digests `veille_alerts`, purge de rétention, GC
hebdomadaire des sources orphelines — missions postérieures (jalons
M3–M4 de Roadmap). La collecte est déclenchée indépendamment des
abonnés (D12).

## 2. Architecture générale

```mermaid
flowchart TD
    CRON[cron ISPConfig<br/>* * * * *] --> SCH[schedule:run]
    SCH -->|everyFiveMinutes| CMD[feeds:fetch<br/>sélection des sources dues]
    SCH -->|everyMinute<br/>withoutOverlapping| QW[queue:work<br/>--stop-when-empty --max-time=50]
    CMD -->|dispatch par source| Q[(queue database)]
    Q --> QW
    QW --> JOB[FetchFeedJob<br/>ShouldBeUnique + Cache::lock]
    JOB --> UV[FeedUrlValidator<br/>schéma + port]
    UV --> DNS[IpResolver<br/>A + AAAA]
    DNS --> POL[IpAddressPolicy<br/>allowlist globale]
    POL -->|IP rejetée| FAIL1[échec source<br/>fail_count++]
    POL -->|IP validée| HTTP[SecureFeedClient<br/>CURLOPT_RESOLVE pinning<br/>redirections ≤ 3 revalidées]
    HTTP -->|304| TOUCH[maj last_fetched_at<br/>last_success_at, fail_count=0]
    HTTP -->|200| PARSE[SimplePieFeedParser<br/>set_raw_data uniquement]
    HTTP -->|erreur| FAIL1
    PARSE --> DTO[FeedItemData[]]
    DTO --> UPS[ArticleUpsertService<br/>dedup_key §4.B]
    UPS --> DB[(articles<br/>UNIQUE source_id+dedup_key)]
    UPS --> META[maj sources : etag,<br/>last_modified, last_success_at]
    PARSE -->|illisible| FAIL1
```

Point d'architecture clé : **tout le réseau passe par
`SecureFeedClient`** — SimplePie n'est jamais autorisé à faire de HTTP
(`set_raw_data()`, `enable_cache(false)`). Le parseur reste pur et
testable hors ligne.

## 3. Composants et responsabilités

| Composant | Namespace proposé | Responsabilité | Dépendances |
|---|---|---|---|
| `FeedUrlValidator` | `App\Services\Feed` | Validation syntaxique pré-réseau : schéma `http`/`https`, ports 80/443 seulement, hôte non vide, IDN → punycode (`idn_to_ascii`), rejet userinfo/fragment. **Imposé** §4.G ; réutilisé par l'ajout de source personnelle. | — |
| `IpResolverInterface` / `DnsResolver` | `App\Services\Feed` | Résolution `A` + `AAAA` (`dns_get_record`), retourne toutes les IP. **Interface injectable → fakes en tests** (rebinding, multi-IP, DNS privés sans réseau réel). | ext-sockets/système |
| `IpAddressPolicy` | `App\Services\Feed` | **Allowlist** : une IP n'est admise que si globalement routable (familles IANA « Global »). Rejet par défaut de tout le reste : privé, bouclage, link-local, multicast, CGN, documentation, benchmarking, réservé, transition 6to4/Teredo non global. Normalise les IPv4-mappées (`::ffff:a.b.c.d`) en IPv4 **avant** le test. Table de préfixes IANA en constante. **Imposé** §4.G. | `inet_pton` |
| `SecureFeedClient` | `App\Services\Feed` | Client HTTP durci (Guzzle 7.16 **déjà présent**, handler cURL) : pipeline valider→résoudre→filtrer→**épingler**→requérir ; boucle de redirection manuelle ≤ 3 avec revalidation intégrale à chaque saut ; timeouts bornés ; corps plafonné ; TLS vérifié. Retourne `FeedFetchResult` (status, headers, body, url effective) ou lève `FeedFetchException` typée. | Guzzle, Validator, Resolver, Policy |
| `FeedParserInterface` / `SimplePieFeedParser` | `App\Services\Feed` | Parse XML brut → `FeedMetadata` (titre, site_url, description) + `FeedItemData[]`. **Jamais de réseau**. Tolérant aux flux mal formés ; signale « illisible » vs « vide ». | `simplepie/simplepie` **à installer** |
| `ArticleUrlNormalizer` | `App\Services\Feed` | Canonisation **B.2** (agressive bornée) : minuscules schéma/hôte, port défaut retiré, fragment supprimé, `www.` initial supprimé, paramètres de tracking retirés (`utm_*`, `fbclid`, `gclid`, `mc_*`), autres paramètres intacts et non réordonnés. Distinct de `normalizeFeedUrl` (B.1, conservatrice) — ne pas mutualiser les deux règles. | — |
| `ArticleUpsertService` | `App\Services` | Orchestrateur de persistance §4.B : calcul `dedup_key`/`url_hash`/`content_hash`, recherche existant, update en place vs insertion, gestion des collisions et de la course d'insertion. Transactionnel. | Eloquent, `SourceSubscriptionService::isUniqueViolation` (réutilisé) |
| `FetchFeedJob` | `App\Jobs` | `ShouldQueue` + `ShouldBeUnique` (verrou `cache_locks` — table existante). Une exécution de bout en bout par source : GET conditionnel → parse → upserts → mise à jour des champs techniques. `tries=1`, `timeout=60`. | tous les services ci-dessus |
| `FetchDueFeeds` (`feeds:fetch`) | `App\Console\Commands` | Sélection des sources dues (§5) + `dispatch` par source. Aucune logique métier. | — |
| `FeedFetchResult`, `FeedItemData`, `FeedMetadata`, `FeedFetchException` | `App\Services\Feed\Data` / `Exceptions` | DTOs typés `readonly` — aucune logique. | — |

Règles d'organisation conformes à `Conventions.md` : contrôleurs minces
(n/a ici — pas d'HTTP entrant), `final` par défaut, typage strict,
FormRequest n/a (pas d'entrée utilisateur nouvelle ; `FeedUrlValidator`
sera branché sur `StorePersonalSourceRequest` lors d'une mission
ultérieure — reporté, hors périmètre M1.10).

## 4. Flux complet d'une collecte

1. `schedule:run` (cron minute) → `feeds:fetch` toutes les 5 min :
   `SELECT id FROM sources WHERE is_active=1 AND orphaned_at IS NULL
   AND (last_fetched_at IS NULL OR last_fetched_at <= now − interval dû)`.
   `interval dû` = délai issu du backoff (§6 — acté). Le critère
   `orphaned_at IS NULL` est **acté (P1)** : une source orpheline n'a
   aucun lecteur ; toute nouvelle référence remet `orphaned_at` à NULL,
   ce qui la rend à nouveau éligible (cohérent D16).
   Pour chaque source : `FetchFeedJob::dispatch($sourceId)`.
2. Worker `queue:work` chaque minute (`--stop-when-empty --max-time=50`,
   `withoutOverlapping`) — pattern déjà arrêté dans Architecture.md §6.
3. Job : `ShouldBeUnique` bloque les doublons en file ;
   `Cache::lock("feeds:source:{id}", 600)` en garde-fou d'exécution
   (cache `database` → `cache_locks`, atomique).
4. `SecureFeedClient->get($feedUrl, etag, lastModified)` — pipeline
   SSRF §5. En-têtes : `If-None-Match`, `If-Modified-Since`,
   `User-Agent: VeilleBot/1.0`, `Accept: application/rss+xml,
   application/atom+xml, application/xml, text/xml, */*`,
   `Accept-Encoding: identity`.
5. `304` → `last_fetched_at` + `last_success_at` = `UTC_TIMESTAMP`,
   `fail_count=0`, `last_error=NULL`. Fin.
6. `200` → corps plafonné lu en streaming → `SimplePieFeedParser` →
   `FeedItemData[]` → transaction : upsert de chaque article (§7) +
   mise à jour `etag`, `last_modified`, `last_fetched_at`,
   `last_success_at`, `fail_count=0`.
7. Erreur (réseau, HTTP ≥ 400, SSRF, parse) → `last_fetched_at` =
   maintenant (horodatage de **tentative**), `fail_count++`,
   `last_error` tronqué (≤ 500). Aucune exception ne sort du job pour
   les échecs de domaine — le job « réussit » fonctionnellement.
8. Exception inattendue → le job échoue (`tries=1`, table
   `failed_jobs`) : distinction nette entre échec de flux prévu et bug.
9. `fail_count` ≥ **10** échecs consécutifs (acté, P2) → `is_active=0`
   + log ; jamais de boucle d'erreurs (critère M2 de Roadmap). Une
   collecte réussie réinitialise `fail_count` à 0. La désactivation
   n'est pas définitive : la réactivation reste possible (`is_active`
   repassé à 1 — aucun verrou n'est prévu).

## 5. Sécurité SSRF — conception détaillée

Tout est **imposé par §4.G** ; ci-dessous, la traduction mécanique dans
le client choisi (Guzzle/cURL). « Application effective » = comment la
mesure est garantie, pas seulement déclarée.

| Mesure §4.G | Application effective |
|---|---|
| Schéma `http`/`https` uniquement | `FeedUrlValidator` : `parse_url` strict + comparaison en minuscules ; tout autre schéma (`file:`, `gopher:`, `ftp:`…) rejeté avant toute E/S. |
| Ports 80/443 uniquement | Port explicite vérifié ; défauts implicites acceptés (80/http, 443/https). Rejet de `:8080`, `:22`, etc. |
| Résolution DNS avant connexion | `dns_get_record($host, DNS_A + DNS_AAAA)` dans `DnsResolver` — exécutée **par notre code**, jamais déléguée à cURL pour la décision. Hôte IDN normalisé en punycode (`idn_to_ascii`, ext-intl présente) avant résolution. Hôte IP-littéral : court-circuite le DNS, passe directement par `IpAddressPolicy`. |
| Validation de **toutes** les IPv4/IPv6 | `IpAddressPolicy->isGloballyRoutable(string $ip): bool` appliquée à chaque IP retournée (`inet_pton` → analyse binaire, pas de regex). |
| Allowlist (rejet par défaut) | Logique positive : n'accepter que les familles IANA « Global ». IPv4 : admis = `0.0.0.0/0` moins le registre complet des usages spéciaux (0/8, 10/8, 100.64/10 CGN, 127/8, 169.254/16, 172.16/12, 192.0.0/24, 192.0.2/24, 192.88.99/24, 192.168/16, 198.18/15, 198.51.100/24, 203.0.113/24, 224/4+, 240/4). IPv6 : admis = `2000::/3` moins les blocs non globaux internes (2001:db8::/32 doc, Teredo 2001::/32, 6to4 2002::/16…). `filter_var(NO_PRIV_RANGE\|NO_RES_RANGE)` **insuffisant seul** (CGN, doc ranges, 192.88.99 non couverts) → table de préfixes dédiée, testée exhaustivement. |
| IPv4-mappées IPv6 | `::ffff:a.b.c.d` détectée via `inet_pton` (octets 10–11 = `ff ff`) → extraite en IPv4 **avant** le test de politique. Bloque le contournement par forme IPv6. Zone-id (`%eth0`) rejetée — jamais globale. |
| Anti-DNS-rebinding | Connexion **à l'IP validée** : `CURLOPT_RESOLVE` = `"host:port:ip"` épinglé dans le handler cURL — aucune seconde résolution entre validation et socket : l'IP de la socket **est** celle qui a passé le test. Le SNI/`Host` restent le nom d'hôte → TLS vérifie le certificat du **nom**, pas de l'IP. Aucune validation mise en cache au-delà de la requête. |
| Toutes les IP validées ? | **Acté (P3 — lecture stricte)** : si **au moins une** IP retournée n'est pas globalement routable, la résolution entière est rejetée (un DNS renvoyant un mélange public+privé est suspect). Alternative écartée : filtrer et n'épingler que les IP valides — sécurité équivalente en pratique (socket épinglée) mais tolère des DNS hybrides et complique l'audit. |
| Failover multi-IP | Toutes les IP retournées étant valides (rejet sinon — règle ci-dessus) : tentatives successives, **une IP épinglée par tentative** (curl 7.88 installé : `CURLOPT_RESOLVE` multi-adresses possible mais une-par-une reste plus déterministe et testable). Connect-error sur IP n → tentative IP n+1 (les erreurs de connexion seulement, pas les erreurs de politique). |
| Redirections ≤ 3, chacune revalidée | **`allow_redirects => false`** : boucle manuelle. Codes gérés : 301/302/303/307/308, méthode GET conservée. À chaque `Location` : résolution de l'URL relative → `FeedUrlValidator` → `DnsResolver` → `IpAddressPolicy` → nouvel épinglage. Une chaîne publique→privée meurt au premier saut invalide. `Referer` non transmis. |
| Validation TLS | Guzzle/cURL `verify` par défaut (peer + hostname) — **jamais** `verify=false`. Minimum TLS 1.2 via `CURLOPT_SSLVERSION`. Le pinning n'affecte pas la validation : cURL conserve le nom pour SNI/`Host`/cert. |
| Proxies d'environnement | `CURLOPT_PROXY` forcé à `''` dans les options cURL du client — neutralise `http_proxy`/`https_proxy`/`all_proxy`/`no_proxy` de l'environnement (libcurl les honore par défaut si non surchargé). |
| Timeouts bornés | **Acté (P4)** : `connect_timeout` **5 s**, `timeout` global **15 s**, `CURLOPT_LOW_SPEED_LIMIT/TIME` = **8 Ko/s pendant 10 s** pour tuer les connexions qui piétinent. |
| Taille max de réponse | **Acté (P5) : 5 Mio** par réponse. **`Accept-Encoding: identity`** imposé (pas de compression → pas de zip-bomb à décompresser). `stream => true` : vérification de la taille annoncée via `Content-Length` dans `on_headers` (> plafond → abort), puis lecture par blocs avec **compteur effectif d'octets reçus** et abort dur au plafond (couvre l'absence ou le mensonge de `Content-Length`). |
| Réponses malformées | Corps passé tel quel à SimplePie (tolérant) dans try/catch ; `Content-Type` non exigé (nombreux flux mal étiquetés) — c'est le parseur qui tranche « lisible/illisible ». Illisible ≠ vide : comptés différemment dans les logs. |
| Débit d'ajout par utilisateur | Hors collecteur : concerne `StorePersonalSourceRequest` — à traiter lors du branchement de `FeedUrlValidator` sur l'ajout (mission ultérieure). Rappelé pour ne pas l'oublier (§4.G). |

### Risques résiduels et limites honnêtes

- `dns_get_record` passe par le **résolveur système** — pas de serveur
  DNS dédié configurable ; acceptable ici (l'épinglage rend le
  rebinding sans effet de toute façon).
- Un domaine dont le DNS retourne en permanence des IP mixtes
  public/privé sera **toujours rejeté** (politique stricte actée, P3) —
  faux positif possible sur des cas exotiques ; la mesure journalisée
  rendra le diagnostic lisible.
- `CURLOPT_RESOLVE` agit par `(host, port)` : une redirection vers le
  même hôte sur un port différent nécessite une entrée distincte —
  couvert par la boucle manuelle.
- HTTP/2 et HTTP/3 : gérés par cURL 7.88 via ALPN si le serveur le
  veut — rien à prévoir côté app.
- Corps `200` sans `Content-Length` + chunked infiniment lent : couvert
  par `timeout` global + low-speed + plafond d'octets — trois barrières
  indépendantes.
- Redirection vers `data:`/`file:`/`javascript:` : éliminée par
  `FeedUrlValidator` au saut concerné (schéma).
- Les flux **authentifiés** ou payants : hors périmètre MVP — aucun
  mécanisme d'identifiants (et les URL avec `user:pass@` sont rejetées
  par le validateur).

## 6. Stratégie de collecte

| Paramètre | Valeur | Statut |
|---|---|---|
| Fréquence par flux | 15 min | **D12 — imposée** |
| Sélecteur | `is_active=1` | imposé |
| Exclusion orphelins | `orphaned_at IS NULL` | **actée (P1)** — cohérent D16 ; une source orpheline n'a aucun lecteur et sa revalidation la rend éligible à nouveau |
| Indépendance des abonnés | oui (catalogue ou personnel, peu importe) | **D12 — imposée** |
| `last_fetched_at` | horodatage de **tentative** (succès ou échec) | sémantique de la colonne §3.2 |
| `last_success_at` | horodatage du dernier 200/304 | idem |
| Backoff échecs | délais successifs **15, 30, 60, 120, 240, 360 min** (plafond 6 h) — voir formule ci-dessous | **actée (P6)** — D12 impose « backoff » sans formule |
| Désactivation | `fail_count` ≥ **10** consécutifs → `is_active=0` ; succès → `fail_count=0` ; réactivation possible | **actée (P2)** — seuil non spécifié dans le modèle |
| `last_error` | message court tronqué (≤ 500), classe d'erreur | colonne existante |
| Concurrence jobs même source | `ShouldBeUnique` (source_id, 900 s) **+** `Cache::lock` en exécution | **actée (P7)** ; `cache_locks` existe ; double barrière simple |
| Timeout job | `timeout=60`, `tries=1` — échec dur → `failed_jobs` | **actée (P7)** |
| Requête conditionnelle | `If-None-Match` + `If-Modified-Since` quand présents | Architecture §6 ; 304 = succès |
| Validation à l'ajout | **Pas** de fetch synchrone à l'ajout de source dans cette mission ; le branchement de `FeedUrlValidator` sur l'URL saisie est reporté | hors scope M1.10 |

**Backoff — ordre des opérations (acté, P6)** : à chaque échec, le job
exécute dans l'ordre :

1. `last_fetched_at = maintenant` (horodatage de la tentative) ;
2. `fail_count = fail_count + 1` — `n` désigne la nouvelle valeur ;
3. prochaine échéance implicite : `last_fetched_at + delay(n)` avec
   `delay(n) = min(15 min × 2^(n−1), 360 min)` → 15, 30, 60, 120, 240,
   puis 360 min pour n ≥ 6 — **le premier échec donne bien 15 min** ;
4. si `n ≥ 10` → `is_active = 0` (la source n'est plus sélectionnée,
   peu importe le délai).

Toute collecte aboutie (200 ou 304) remet `fail_count` à 0 → le délai
redevient 15 min. Conséquence mesurée : le seuil de désactivation est
atteint au plus tôt au 10ᵉ échec consécutif, après ~36 h de tentatives
étalées.

Aucune table ni colonne nouvelle : `fetch_logs` explicitement différée
(D7) ; tout l'état de collecte tient dans les colonnes existantes de
`sources`.

## 7. Déduplication et intégrité (§4.B — imposé)

### Entrées par item (DTO `FeedItemData`)

`guid` déclaré, `guidIsPermalink` (RSS2 : attribut `isPermaLink` lu dans
les tags bruts de l'item — SimplePie l'expose via `get_item_tags()` ;
Atom `<id>` est par spécification un identifiant permanent → traité
comme exploitable), `url`, `title`, `summary`, `content`, `author`,
`publishedAt`.

### Chaîne de calcul

- `dedup_key` = sha256 du **premier** de : `guid` si exploitable →
  `url` normalisée (B.2) → `title|published_at|summary[:120]`
  (fallback, résidu de collision documenté §8 du modèle — accepté).
- `url_hash` = sha256(`url` normalisée B.2) — toujours calculé, même
  quand le GUID prime (c'est la clé de rattrapage).
- `content_hash` = sha256 sur concaténation bornée des champs mutables
  (**acté, P8** : `title`, `author`, `summary`, `content`, chacun
  trimmé, joints par le séparateur non ambigu `\x1f` ; les champs
  volatils — `url`, `published_at`, `guid` — sont exclus. Un test de
  déterminisme figera la composition à l'implémentation).
- `collected_at` = `UTC_TIMESTAMP` au premier insert ; jamais modifié
  ensuite.

### Arbre de décision par item (dans la transaction de batch)

```
dedup_key connu pour cette source ?
├── OUI → content_hash identique ?
│         ├── oui → rien (compteur "inchangé")
│         └── non → UPDATE champs mutables (title, summary, content,
│                   author, url/url_hash si l'URL a bougé — §4.B :
│                   clé issue du GUID ⇒ update simple) + updated_at.
│                   collected_at et article_user/* préservés.
└── NON → lignes de la source avec même url_hash ?
          ├── exactement 1 → GUID changé : UPDATE de cette ligne
          │   (guid, dedup_key, url, champs) — sauf collision :
          │   si le nouveau dedup_key existe déjà sur une AUTRE
          │   ligne → pas d'écrasement, pas de fusion ; la ligne au
          │   plus ancien id conserve sa clé, l'entrante la sienne ;
          │   anomalie journalisée (§4.B). Le point exact du repli
          │   (mise à jour partielle vs nouvelle ligne) sera figé
          │   par un test dédié à l'implémentation — sous-cas rare.
          ├── plusieurs → ambiguïté : INSERT nouvelle ligne, jamais
          │   de fusion.
          └── aucune → INSERT nouvelle ligne.
INSERT → UNIQUE violation (course) → relecture par
(source_id, dedup_key) via le même chemin « clé connue » —
`SourceSubscriptionService::isUniqueViolation` (23000/1062/19+
message) réutilisé pour distinguer doublon réel d'erreur autre.
```

**Garanties** : `UNIQUE(source_id, dedup_key)` = seule barrière dure ;
aucune fusion silencieuse — `article_user`/`article_veille` ne sont
jamais déplacés d'une ligne à l'autre ; les cas limites du modèle
(URL changeante sans GUID stable → nouvelle ligne) sont acceptés tels
quels, non « corrigés ».

### Transaction

**Acté (P9)** : une transaction atomique par batch de source
(instantané : tout le flux ou rien) ; un item invalide en données (URL
absente, titre vide) est **sauté + compté**, il ne fait pas échouer le
batch ; une erreur **systémique** (connexion, deadlock, violation non
liée à un item) provoque le rollback du lot. Équivalent SQLite/MariaDB
maintenu pour la double suite de tests.

## 8. Gestion des erreurs, concurrence et observabilité

- **Isolation des pannes** : chaque source dans son job ; une exception
  fatale d'un job ne touche pas la file (`tries=1` → `failed_jobs`).
- **Taxonomie d'erreurs** : `FeedFetchException` sous-types
  (`SsrfRejected`, `DnsFailed`, `HttpError`, `BodyTooLarge`,
  `Timeout`, `ParseFailed`) → `last_error` et log identifient la cause
  sans ambiguïté ; `fail_count` ne distingue pas les causes (modèle).
- **Codes HTTP** : 2xx → traitement ; 304 → succès sans corps ;
  3xx non géré par la boucle (> 3 sauts ou Location absente) → échec ;
  401/403/404/410/5xx/429 → échec (le backoff couvre 429 ;
  `Retry-After` non exploité — décision actée P10, le backoff suffit
  pour le MVP).
- **Logs** (`Log` facade, canal par défaut) : INFO par source collectée
  `{source_id, status, duration_ms, items_new, items_updated,
  items_skipped}` ; WARNING par échec `{source_id, error_class,
  fail_count}` ; ERROR sur anomalie de dédup/collision. **Jamais** de
  corps de flux, d'en-têtes d'auth, de secrets — `feed_url` et le
  message d'erreur tronqué suffisent.
- **Métriques** : pas d'outil dédié ; les compteurs du log + les
  colonnes de `sources` couvrent le besoin MVP.

## 9. Stratégie de tests

Bi-moteur obligatoire : `phpunit.xml` (SQLite `:memory:`) +
`phpunit.mariadb.xml` (`veille_test`). **Aucun appel réseau réel** :
`IpResolverInterface` faké + `MockHandler` Guzzle pour les réponses.

| Couche | Cas couverts |
|---|---|
| `FeedUrlValidator` (unit) | schémas refusés (`file`, `gopher`, `ftp`, `data`), ports refusés, userinfo, fragment, IDN, hôte IP-littéral, URL relative |
| `IpAddressPolicy` (unit, matrice exhaustive) | 10/8, 172.16/12, 192.168/16, 127/8, 169.254/16, 100.64/10, 0/8, 224/4+, 240/4, doc ranges ×3, 198.18/15, 192.88.99 ; IPv6 : ::1, fe80::, fc00::/7, ff00::/8, ::ffff:x.x.x.x mappée, 2001:db8, Teredo, vs globales 8.8.8.8 / 2606:4700:: — plages futures = rejet par défaut |
| `SecureFeedClient` (mock DNS+HTTP) | IP privée → rejet pré-connexion ; multi-IP (1 privée + 1 publique) → comportement P3 vérifié ; rebinding (résolveur basculant) → épinglage vérifié ; chaîne de redirections publique→privée → abort au saut ; > 3 sauts → échec ; `Content-Length` > plafond → abort ; corps sans length dépassant le plafond → abort au compteur ; timeout simulé ; `CURLOPT_RESOLVE` effectivement posé (assert options) |
| `SimplePieFeedParser` (fixtures `tests/fixtures/feeds/`) | RSS2 valide, Atom valide, RSS malformé (XML cassé), flux vide, page HTML, encodage hétérogène, items sans date/sans guid/guid non-permalink, `<id>` Atom, taille limite |
| `ArticleUpsertService` (intégration bi-moteur) | insert nouveau ; doublon exact → rien ; content_hash modifié → update en place, `collected_at` et `article_user` préservés ; GUID changé + url_hash unique → update de la ligne ; url_hash multiple → insertion distincte ; collision de clé → pas de fusion + log ; course d'insertion simulée (ligne créée entre select et insert) → relecture, pas de doublon ; violation non-unique propagée |
| `FetchFeedJob` / `feeds:fetch` (intégration) | sélection due/pas due/inactive/orpheline ; 304 → champs maj ; 200 → articles insérés + etag stocké ; échec → `fail_count++`, `last_error` ; seuil → `is_active=0` ; `ShouldBeUnique`/`Cache::lock` → pas de double exécution ; échec source A n'affecte pas source B |
| MariaDB spécifique | errno 1062 réel sur `UNIQUE(source_id,dedup_key)` ; comparaisons `UTC_TIMESTAMP(6)` ; FK `articles.source_id` RESTRICT |

Fixtures commitées dans `tests/fixtures/feeds/` (Conventions.md) —
petites, anonymisées, un fichier par cas.

## 10. Décisions actées

**Imposées par DataModel.md v3.4 / Architecture.md — inchangées** :
fréquence 15 min et indépendance des abonnés (D12) ; allowlist IP
globale, IPv4-mappées, épinglage anti-rebinding, ≤ 3 redirections
revalidées, UA `VeilleBot/1.0`, TLS vérifié, pas de proxy d'env (§4.G) ;
règles de déduplication complètes (§4.B) ; orphelinage/GC (D16) ;
queue `database`, worker cron (Architecture §6) ; UTC strict,
`UTC_TIMESTAMP(6)`.

**Décisions techniques actées** — les 12 propositions de conception ont
été **validées sans réserve par le maître d'ouvrage** (fin M1.10.1).
Elles valent décision d'implémentation et ne sont plus discutables sans
révision explicite :

| # | Sujet | Décision actée |
|---|---|---|
| P1 | Éligibilité collecte | Exclure les sources `orphaned_at IS NOT NULL` |
| P2 | Désactivation | `fail_count ≥ 10` échecs consécutifs → `is_active = 0` ; succès → compteur à 0 ; réactivation possible (pas de verrou définitif) |
| P3 | IP mixtes | Rejet de la résolution si **au moins une** IP retournée n'est pas globalement routable |
| P4 | Timeouts | Connexion 5 s ; durée totale 15 s ; low-speed 8 Ko/s pendant 10 s |
| P5 | Taille maximale | 5 Mio par réponse ; `Accept-Encoding: identity` ; contrôle `Content-Length` **et** comptage effectif des octets |
| P6 | Backoff | `delay(n) = min(15 min × 2^(n−1), 360 min)` où `n` = `fail_count` après incrément → 15, 30, 60, 120, 240, 360 min ; **premier échec = 15 min** |
| P7 | Job | `tries=1`, `timeout=60`, `ShouldBeUnique` 900 s + `Cache::lock` en exécution |
| P8 | `content_hash` | sha256 de `title`, `author`, `summary`, `content` trimmés, joints par `\x1f` ; champs volatils (`url`, `published_at`) exclus |
| P9 | Transaction | Une transaction atomique par source ; item invalide = ignoré + compté ; erreur systémique = rollback du lot |
| P10 | `Retry-After` | Non exploité — le backoff P6 suffit pour le MVP |
| P11 | Package | `simplepie/simplepie` ; à l'installation, vérifier que la version stable retenue est publiée depuis ≥ 7 jours ; **Guzzle 7.16 déjà présent**, aucune autre dépendance |
| P12 | Enrichissement | Renseigner `name`, `site_url`, `description` **uniquement si vides** ; jamais d'écrasement d'une valeur existante |

Cohérence vérifiée : aucune de ces décisions ne contredit
`DataModel.md` v3.4 ni `Architecture.md` ; elles ne modifient ni le
schéma (pas de table/colonne nouvelle) ni les règles métier existantes.

## 11. Découpage proposé des missions

| Mission | Contenu | Livrable vérifiable |
|---|---|---|
| **M1.10.2** — Fondations SSRF | `composer require simplepie/simplepie` (version ≥ 7 jours) ; `FeedUrlValidator`, `IpResolverInterface`+`DnsResolver`, `IpAddressPolicy`, `SecureFeedClient` (+exceptions, DTO résultat) | Tests unitaires SSRF exhaustifs verts bi-moteur ; aucun code de collecte |
| **M1.10.3** — Parsing | `SimplePieFeedParser`, `FeedItemData`/`FeedMetadata`, `ArticleUrlNormalizer` (B.2), fixtures | Suite fixtures verte ; extraction isPermaLink RSS2 / `<id>` Atom démontrée |
| **M1.10.4** — Déduplication | `ArticleUpsertService` : chaîne de clés, arbre §4.B, course d'insertion, transaction batch | Tests d'intégration §4.B verts **sur les deux moteurs** (dont collision + ambiguïté) |
| **M1.10.5** — Orchestration | `FetchFeedJob`, `feeds:fetch`, scheduler `routes/console.php`, backoff/seuil, logs | Job E2E avec client faké : 200/304/échec/seuil/orphelin/concurrence ; docs scheduler |
| **M1.10.6** — Revue et commit(s) | `pint`/`phpstan`/tests bi-moteur, revue diff, mise à jour docs impactées (Architecture.md §6 si écart, REPRISE_PROJET.md) — les modifs de docs **dans le même commit** | Commit(s) validé(s) par Gaël |

Chaque mission = un livrable testable séparément, un commit par étape
cohérente (Conventions.md §Git).

## 12. Synthèse — état de la conception

**Décisions actées (P1–P12, validées sans réserve)** : éligibilité hors
orphelins ; seuil 10 échecs → `is_active=0` avec reset au succès et
réactivation possible ; rejet strict des DNS mixtes ; timeouts
5 s/15 s + low-speed ; plafond 5 Mio en `identity` ; backoff
15→360 min (premier échec = 15 min) ; job `tries=1`/`timeout=60` +
double verrou ; `content_hash` sur 4 champs séparés `\x1f` ;
transaction atomique par source ; `Retry-After` ignoré ;
`simplepie/simplepie` ≥ 7 jours ; enrichissement si vide seulement.

**Imposé et conservé** : D12, §4.B, §4.G, D16, queue `database`,
UTC/`UTC_TIMESTAMP(6)` — aucune contradiction identifiée, aucun
amendement des documents de référence requis.

**Reste à implémenter** : les composants §3 (aucun code n'existe),
`simplepie/simplepie` non installé, scheduler non branché, fixtures de
flux non créées — tout le travail de M1.10.2 à M1.10.6.

---

**CONCEPTION VALIDÉE — M1.10.1 TERMINÉE**

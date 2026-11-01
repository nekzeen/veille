# Reprise du projet Veille

> Document d'accueil pour une nouvelle session de développement.
> État vérifié le 2026-11-01 au commit `c1588e4` (branche `main`) ;
> travaux M1.10 consolidés par la série de commits M1.10.3.3 (§8).
> **À lire après `AGENTS.md`** — celui-ci reste la référence des
> interdictions et règles non négociables.

## 1. Présentation

**Veille** est une plateforme de veille informationnelle
multi-utilisateurs, fonctionnellement inspirée d'Inoreader.

- Chaque utilisateur dispose d'un **espace privé autonome** : ses veilles
  (nom + mots-clés + sources), favoris, notes, catégories et alertes ne
  sont jamais visibles par les autres.
- **Mutualisation technique, pas fonctionnelle** : un même flux n'est
  téléchargé qu'une fois (`sources`/`articles` partagés), mais chaque
  utilisateur a sa propre vue (lu/non lu, favoris, notes) via
  `article_user` et `article_veille`.
- **Aucune logique pédagogique** : pas de formateur/apprenant/groupes —
  ne jamais en introduire.
- Le partage, s'il arrive un jour, est **facultatif et explicite**.

**Périmètre MVP** : comptes utilisateurs (Breeze), veilles personnelles
(mots-clés + sources), catalogue de sources + sources personnelles par
URL, lecture lu/non lu, favoris/notes/catégories, alertes e-mail
périodiques. Hors périmètre : OPML, partage, recherche plein texte, API,
filtres avancés (voir PROJECT.md §Périmètre).

## 2. Architecture

Monolithe **Laravel 12**, Blade + Alpine.js + Tailwind CSS (build **Vite**
via Node 22/nvm — jamais de Node en production), **MariaDB 10.11**,
authentification **Laravel Breeze** (sessions `file`, cookies
secure/HttpOnly).

| Composant | Choix |
|---|---|
| Queue + cache | driver `database` (pas de Redis — présent mais partagé, non retenu) |
| Scheduler | un seul cron ISPConfig → `php artisan schedule:run` ; aucun worker persistant |
| Mail | sendmail (Postfix local) prévu en prod — `.env` actuel encore sur `log` |
| Parsing flux | **SimplePie 1.9.0** — installé (`composer.json`) |
| Organisation cible | Actions / Services / Jobs / Enums (décrite dans Architecture.md §4) |

**Réellement présent aujourd'hui** : scaffolding Laravel + Breeze,
modèles `Source`/`UserSource`, `SourceSubscriptionService`,
`UserSourcePolicy`, contrôleurs `CatalogueController`/`UserSourceController`,
`StorePersonalSourceRequest`, vues `sources/*`, factories et tests,
**collecteur RSS/Atom** : `app/Services/Feed/`
(`SecureFeedClient` anti-SSRF, `FeedUrlValidator`, `IpAddressPolicy`,
`DnsResolver`, `SimplePieFeedParser`, interfaces, DTO, exceptions) +
`app/Enums/FeedFormat.php`.
**Pas encore implémentés** : orchestration de la collecte
(`ArticleUpsertService` §4.B, `ArticleUrlNormalizer` B.2 — reportés à
M1.10.4), jobs, scheduler actif (`routes/console.php` est le squelette
par défaut), matching, notifications, dossiers `Actions/`/`Jobs/`/
`Notifications/`.

## 3. Hébergement

Debian 12 + **ISPConfig** + Apache + **PHP-FPM 8.3** (socket
`/var/lib/php8.2-fpm/web18.sock` — nom historique, PHP réel = 8.3).

- Racine privée du projet : `/var/www/clients/client2/web18/private/veille`
- DocumentRoot : `/var/www/clients/client2/web18/web` (géré par ISPConfig)
- Site : `https://veille.gaelmorvan.fr`
- **Jamais exposer** `.env`, `vendor/`, `storage/` dans le webroot.
- **Ne jamais modifier** le vhost Apache ni les pools FPM ; ne pas
  supprimer `error/`, `stats/`, `standard_index.html` dans `/web`.

> ⚠️ **État réel** : `/web` n'est **pas encore déployé** — aucun
> `index.php` Laravel, `.htaccess` ni symlinks `build`/`storage` ; seuls
> les fichiers ISPConfig s'y trouvent. La procédure d'exposition est
> prête dans Deployment.md. `.env` est en mode dev (`APP_ENV=local`,
> `APP_DEBUG=true`, `MAIL_MAILER=log`) — à durcir au déploiement.

## 4. Bases de données — ne jamais les confondre

| Base | Rôle | État vérifié |
|---|---|---|
| `veille_db` | **Applicative** | 3 migrations framework Ran ; **10 migrations métier Pending** — ne rien exécuter sans validation explicite |
| `veille_test` | **Tests uniquement** | **13 migrations Ran** (3 framework + 10 métier, batch 1) |

- Utilisateur : `veille_usr` (accès vérifié aux deux bases).
- **Fuseau de session MariaDB** : `'timezone' => env('DB_TIMEZONE', '+00:00')`
  dans `config/database.php` → `set time_zone='+00:00'` à chaque
  connexion (UTC strict, cf. DataModel §3).
- `phpunit.xml` : SQLite `:memory:` (défaut, rapide).
- `phpunit.mariadb.xml` : `DB_CONNECTION=mariadb`,
  `DB_DATABASE=veille_test`, `DB_TIMEZONE=+00:00` →
  `php artisan test -c phpunit.mariadb.xml`.
- **Toute exécution de migrations métier se fait exclusivement sur
  `veille_test`** jusqu'à validation de Gaël pour `veille_db`.

## 5. Modèle de données

Références : **DataModel.md v3.4** (modèle, 771 lignes — source de
vérité) et **MigrationPlan.md** (implémentation des 10 migrations).

Décisions structurantes :

- **10 tables métier** : `categories`, `sources`, `user_sources`,
  `veilles`, `veille_keywords`, `source_veille`, `articles`,
  `article_veille`, `article_user`, `veille_alerts`.
- `sources`/`articles` = identité **technique** mutualisée, sans droits ;
  accès = `is_catalogue=1` ∪ ligne `user_sources`.
- **Isolation par base** : enfants de `veilles` portent `user_id`
  dénormalisé + **FK composites** vers `veilles(id,user_id)` et
  `categories(user_id,id)` — la base refuse le mauvais tenant.
- Déduplication articles : `dedup_key` sha256 (guid → url normalisée →
  titre|date|résumé), `UNIQUE(source_id, dedup_key)`, jamais de fusion
  silencieuse (§4.B).
- Orphelinage : source non catalogue sans `user_sources`/`source_veille`/
  `articles` → `orphaned_at` ; GC après 7 jours (D16, §4.A).
- **UTC partout** : timestamps stockés UTC, comparaisons en
  `UTC_TIMESTAMP(6)` — jamais `NOW()` ; colonnes de bornes de nouveauté
  en `timestamp(6)`. Seule `digest_date` est calculée en `Europe/Paris`
  (fuseau logique, §4.C).
- **SSRF (futur collecteur, §4.G)** : `http(s)` + ports 80/443 ;
  **allowlist** IP globalement routables (toute IP privée/réservée/
  spéciale rejetée par défaut) ; IPv4-mappées normalisées ; anti-DNS-
  rebinding (connexion à l'IP validée, `CURLOPT_RESOLVE`) ; ≤ 3
  redirections chacune revalidée ; `User-Agent: VeilleBot/1.0` ;
  timeouts et taille bornés ; TLS vérifié obligatoire.

## 6. Fonctionnalités implémentées

| Fonctionnalité | État |
|---|---|
| Authentification Breeze (inscription, login, vérif e-mail, reset, profil) | ✅ commit `907893f` |
| Catalogue des sources (liste `is_catalogue`, recherche, pagination, badge abonné) | ✅ |
| Abonnements personnels (`user_sources`, UNIQUE(user_id,source_id)) | ✅ |
| Ajout de source personnelle par URL (validation http/https, normalisation B.1, réutilisation de la source technique, gestion des doublons concurrents SQLSTATE 23000 / MariaDB 1062 / SQLite 19) | ✅ |
| Désabonnement transactionnel + `orphaned_at` | ✅ |
| Isolation (Policy `UserSource`, routes `auth`) | ✅ |
| **Collecte effective des flux** : client HTTP `SecureFeedClient` + parseur `SimplePieFeedParser` | ✅ livrés (M1.10.2.x–M1.10.3.2) |
| Orchestration collecte (`ArticleUpsertService`, `FetchFeedJob`, `feeds:fetch`, scheduler) | ❌ non implémentée (M1.10.4+) |
| Veilles, mots-clés, matching, favoris/notes/catégories, alertes | ❌ postérieurs |
| Déploiement `/web` + cron scheduler | ❌ prérequis manuels ISPConfig |

## 7. Tests

Résultats vérifiés (ne pas extrapoler) :

- **25 tests initiaux** (Breeze : auth, profil, exemples) — validés
  SQLite et MariaDB en M1.8.
- **188 tests** (421 assertions) après le collecteur RSS/Atom
  (M1.10.2.x–M1.10.3.2) — verts sur SQLite (`php artisan test`) et sur
  MariaDB `veille_test` (`-c phpunit.mariadb.xml`) ; bi-moteur
  vérifié le 2026-11-01 sur la série M1.10.3.3.
- `./vendor/bin/phpstan analyse` : **aucune erreur** (Larastan).
- `./vendor/bin/pint --test` : **passed**.
- Les migrations métier n'ont été exécutées que sur `veille_test`.

Commandes : `php artisan test` · `php artisan test -c
phpunit.mariadb.xml` · `./vendor/bin/pint --test` ·
`./vendor/bin/phpstan analyse` — les trois contrôles doivent passer
avant tout commit.

## 8. Git

Branche : `main` — dernier commit `c1588e4`.

```
c1588e4 docs: add project handover document                     ← reprise
9e47ca1 feat: add source catalogue and personal subscriptions   ← M1.9
6504116 test: add MariaDB PHPUnit configuration                 ← M1.8
ef33b05 feat: add initial application database migrations       ← M1.7
e09000b chore: configure Larastan static analysis
907893f feat: add Breeze Blade authentication
49d214a feat: initialize Laravel 12 application
a91098b docs: define Git workflow rules
58e35d7 docs: initialize Veille project documentation
```

- Remote : `origin = https://github.com/nekzeen/veille.git` —
  `origin/main` **inexistante** : les commits sont locaux, **jamais
  poussés** (push uniquement sur validation explicite de Gaël).
- `.env`, `vendor/`, `node_modules/`, `public/build/` : ignorés, jamais
  commités.
- **Travaux M1.10.2.x–M1.10.3.2 (audités)** : `composer.json` +
  `composer.lock` (SimplePie 1.9.0), `Collecteur_RSS_Architecture.md`,
  `app/Enums/FeedFormat.php`, `app/Services/Feed/` (client sécurisé,
  validateur, politique IP, résolveur, parseur, DTO, exceptions),
  tests unitaires associés et `tests/fixtures/feeds/` (11 fixtures) —
  consolidés en 4 commits (M1.10.3.3 : docs, dépendance, parseur,
  client HTTP sécurisé).

## 9. Missions

Terminées (numérotation fine M1.x — distincte des jalons M0–M5 de
Roadmap.md, le module Sources correspond au jalon M2) :

| Mission | Livrable |
|---|---|
| M1.5 | DataModel.md **v3.4** figé |
| M1.6 | MigrationPlan.md (10 migrations, ordre, tests) |
| M1.7 | 10 migrations Laravel écrites (commit `ef33b05`) |
| M1.8 | Validation MariaDB + `phpunit.mariadb.xml` (commit `6504116`) |
| M1.9 | Module Sources : implémentation (M1.9.1), audit + corrections QueryException/UTC (M1.9.2–M1.9.4), commit `9e47ca1` (M1.9.5) |
| M1.10.1 | `Collecteur_RSS_Architecture.md` — conception collecteur, décisions P1–P12 actées |
| M1.10.2.1 | `simplepie/simplepie` 1.9.0 installé |
| M1.10.2.2 | `SimplePieFeedParser` + DTO + `FeedFormat` + fixtures |
| M1.10.3 | `SecureFeedClient` + `FeedUrlValidator` + `IpAddressPolicy` + `DnsResolver` |
| M1.10.3.1 | Audit global : anomalie URL/enclosure trouvée et corrigée, faux positifs hostnames hex, codes 3xx non gérés, frontières IPv6 — corrections M1.10.3.2 |
| M1.10.3.3 | Consolidation Git du collecteur : 4 commits (docs, SimplePie, parseur, client HTTP) — aucun push |

**Prochaine mission — M1.10.4 : déduplication et persistance des
articles.** Points à traiter :

- `ArticleUpsertService` : chaîne `dedup_key`/`url_hash`/`content_hash`
  (§4.B, P8), arbre de décision, transaction atomique par source (P9) ;
- **`ArticleUrlNormalizer` (B.2) — explicitement reporté ici** depuis le
  livrable parsing (point de consommation = la déduplication) ;
- GET conditionnel ETag/Last-Modified déjà supporté par
  `SecureFeedClient::fetch()` ; `fail_count` + backoff P6, seuil
  `is_active` P2 à câbler au job ;
- job `FetchFeedJob` (P7) + `feeds:fetch` + scheduler viennent ensuite
  (M1.10.5), puis revue/consolidation Git (M1.10.6).

## 10. Règles de travail avec Devin

- **Une mission à la fois** ; prompts ciblés, optimisés en coût.
- **Lire les documents pertinents avant d'intervenir** (AGENTS.md en
  premier, puis le document du domaine touché — cartographie dans
  AGENTS.md).
- Tests **obligatoires sur les deux moteurs** : SQLite (`phpunit.xml`)
  et MariaDB (`phpunit.mariadb.xml` → `veille_test` exclusivement).
- **Jamais de modification de `veille_db`** (migrations incluses) sans
  autorisation explicite ; jamais de `migrate:fresh`/`db:wipe` en prod.
- **Aucun commit ni push sans autorisation** ; un commit par étape
  cohérente validée (Conventions.md §Git).
- **Ne jamais modifier une décision documentée** (Architecture.md,
  DataModel.md, MigrationPlan.md) sans justification et validation —
  toute évolution des docs se fait dans le même commit que le code.
- Interdictions serveur (AGENTS.md) : pas de vhost/pools FPM, pas
  d'autres sites, pas de paquet système, pas de daemon persistant, pas
  d'écriture dans `/web` hors procédure validée.

## Écarts connus entre documents et état réel

- `.env` `APP_TIMEZONE=Europe/Paris` : **inerte** — `config/app.php`
  fige `'timezone' => 'UTC'` (skeleton Laravel 12). La convention UTC
  est donc structurelle ; `Europe/Paris` n'est que le fuseau logique
  métier (`digest_date`). À clarifier si un fuseau d'affichage est
  requis plus tard.
- `APP_DEBUG=true` / `APP_ENV=local` / `MAIL_MAILER=log` : état dev —
  valeurs prod attendues dans Development.md §.env.
- `DB_TIMEZONE` absent de `.env` : valeur par défaut `+00:00` prise
  dans `config/database.php` ; fixé explicitement dans
  `phpunit.mariadb.xml`. Expliciter dans `.env` reste une bonne pratique.
- `Source::create()` reçoit `created_at`/`updated_at` non fillables
  (ignorés par `fill()`) — les auto-timestamps Eloquent écrivent en UTC
  de fait ; code à nettoyer à l'occasion (relève de l'audit M1.9.4).

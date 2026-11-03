# Reprise du projet Veille

> Document d'accueil pour une nouvelle session de développement.
> État vérifié le 2026-11-05 (branche `main`, synchronisée avec
> `origin/main` au commit `4f3bc92`) ; collecteur RSS/Atom complet
> et planifié (M1.10.5.x, voir `Orchestration_Collecteur_RSS.md`) ;
> module Veilles (M1.11), lecture des articles (M1.12), états
> personnels — lu/non lu, favoris, notes, archivage (M1.13) et
> catégories (M1.14) — et système d'alertes quotidiennes complet
> (M5, voir §9) : tous livrés, testés bi-moteur et publiés.
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
`app/Enums/FeedFormat.php` ; **collecte opérationnelle** :
`ArticleUpsertService`, `FetchFeedJob`, commande `feeds:fetch`
(`FetchFeedsCommand`), scheduler dans `routes/console.php`
(`feeds:fetch` /5 min + `queue:work --stop-when-empty --max-time=50`
/min, `withoutOverlapping` à TTL arbitré 15/10 min) et cron ISPConfig
installé (`schedule:run` chaque minute sous `web18`) ; **module
Veilles complet** (CRUD, mots-clés, matching, rematching) ; **lecture
des articles** (liste/fiche sanitisées, CSP, pagination) ; **états
personnels** `article_user` (lu/non lu, favoris, notes, archivage,
catégories — espace « Mes favoris / archives / catégories ») ;
**alertes quotidiennes** : génération `alerts:generate` (curseur
`article_veille.id` sous verrou `veilles`, ≤50), outbox
`veille_alerts` (réservation atomique, bail 10 min, 3 tentatives),
envoi `VeilleDigestMail` (HTML+texte), reprise `alerts:sweep` —
scheduler complet dans `routes/console.php`.
**Pas encore implémentés** : post-MVP uniquement (OPML, partage,
recherche plein texte, API — Roadmap.md).

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
| `veille_db` | **Applicative** | 3 migrations framework Ran ; **12 migrations métier Pending** — ne rien exécuter sans validation explicite |
| `veille_test` | **Tests uniquement** | **15 migrations Ran** (3 framework + 12 métier) |

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
| Orchestration collecte (`ArticleUpsertService`, `FetchFeedJob`, `feeds:fetch`, scheduler, verrous P6/P7) | ✅ livrée et testée bout-en-bout (M1.10.4–M1.10.5) |
| Cron ISPConfig `schedule:run` chaque minute | ✅ installé, exécution confirmée |
| **Module Veilles** : modèles+FK composites (M1.11.1), CRUD+Policy (M1.11.2), `KeywordMatcher` (M1.11.3), intégration `FetchFeedJob` (M1.11.4), `VeilleRematcher`+`veilles:rematch` (M1.11.5) | ✅ livré et commité |
| Page de lecture des articles par veille (liste filtrée, pagination, lecture interne assainie, prev/next, CSP) | ✅ livrée et commitée (M1.12) |
| Lu/non lu, favoris, notes, archivage (`article_user`, espaces personnels) | ✅ livrés et commités (M1.13) |
| Catégories personnelles (CRUD, classement, listes, transaction §4.F) | ✅ livrées et commitées (M1.14) |
| Alertes e-mail quotidiennes (génération, outbox, envoi, reprise, scheduler) | ✅ livrées et commitées (M5) |
| Déploiement `/web` | ❌ prérequis manuels ISPConfig |
| Migrations métier sur `veille_db` | ❌ en attente d'autorisation explicite |

## 7. Tests

Résultats vérifiés (ne pas extrapoler) :

- **25 tests initiaux** (Breeze : auth, profil, exemples) — validés
  SQLite et MariaDB en M1.8.
- **696 tests** (2026 assertions) après la revue M5.6 — verts sur SQLite
  (`php artisan test`) et sur MariaDB `veille_test`
  (`-c phpunit.mariadb.xml`) ; bi-moteur vérifié le 2026-11-03.
  La revue M1.13.x a extrait la règle d'accès autonome dans
  `app/Services/Articles/ArticlePersonalAccess.php` (`hasAccess` —
  partagée par favoris, notes, archives).
- `./vendor/bin/phpstan analyse` : **aucune erreur** (Larastan).
- `./vendor/bin/pint --test` : **passed**.
- `composer validate` : **valid** ; `composer audit` : aucune
  vulnérabilité connue.
- Les migrations métier n'ont été exécutées que sur `veille_test`.

Commandes : `php artisan test` · `php artisan test -c
phpunit.mariadb.xml` · `./vendor/bin/pint --test` ·
`./vendor/bin/phpstan analyse` — les trois contrôles doivent passer
avant tout commit.

## 8. Git

Branche : `main` — dernier commit `4f3bc92`, synchronisé avec
`origin/main`.

```
4f3bc92 docs: handover for M5                                   ┐
07577b8 test: cover digest generation and outbox                │
ef141f1 feat: add digest outbox and mail delivery               │ M5
6366dbd feat: generate daily digests                            │
c8a5326 feat: serialize article_veille writes                   │
80a4eea feat: add match watermark for digest cursors            ┘
d3a49eb docs: update project handover for M1.14                 ┐
c686cc4 test: cover personal article categories                 │
d2c2664 feat: add category management interface                 │ M1.14
5f34c7a feat: add personal article categories                   ┘
f23c364 docs: update project handover for M1.13                 ┐
6c181fa test: cover personal article states                     │
423785d feat: add personal article actions and spaces           │ M1.13
98b3897 feat: add per-user article state services               ┘
8e8b612 docs: update project handover for M1.12                 ← M1.12 (consolidé et publié)
1434635 docs: update project handover for veilles module        ← M1.11
9463e8b feat: match articles during RSS feed collection         ← M1.11.4
1200042 feat: add veille CRUD with keyword and source management ← M1.11.2
7185b38 feat: add veille rematcher and rematch command          ← M1.11.5
0445a73 feat: add keyword matcher service                       ← M1.11.3
0200653 feat: add veille models with tenant isolation           ← M1.11.1
d74bd12 docs: add RSS collector orchestration and update handover
fa27b14 test: add integrated RSS collector tests                ┐
063f980 feat: add RSS collector scheduler                       │
9eadea7 feat: add feed collection dispatch command              │ M1.10
8b019e6 feat: add RSS feed fetch job                            │ (détail dans
a9ff061 feat: add article deduplication and persistence         │  Orchestration_
3268cba feat: add SSRF-hardened feed HTTP client                │  Collecteur_RSS.md)
46cc76c feat: add SimplePie RSS/Atom parser                     │
9748b9f build: require simplepie/simplepie 1.9                  ┘
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
  **`origin/main` pointe sur `4f3bc92`** ; branche locale `main`
  **synchronisée** avec `origin/main` (vérifié le 2026-11-05, M1.12
  à M5 poussés par Gaël). Tout push futur reste soumis à validation
  explicite de Gaël.
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
| M1.10.4–M1.10.5 | `ArticleUpsertService`, `FetchFeedJob`, `feeds:fetch`, scheduler — collecte opérationnelle bout-en-bout |
| M1.11.1 | Modèles `Veille`/`VeilleKeyword`/`ArticleVeille`, relations, FK composites, `VeillePolicy` |
| M1.11.2 | CRUD veilles (routes `auth`, FormRequests, `VeilleService`, vues Blade), abonnement auto sur source catalogue |
| M1.11.3 + M1.11.3.1 | `KeywordMatcher` (normalisation PHP NFD+diacritiques, sous-chaîne OR, `matched_keyword` ordonné) + consolidation (parsing, limites, espaces Unicode) |
| M1.11.4 | Matching dans `FetchFeedJob` sur les articles du lot (`ArticleUpsertResult::articleIds`) ; erreur matcher journalisée, collecte préservée |
| M1.11.5 | `VeilleRematcher` (diff d'ensembles, fenêtre 6 mois `COALESCE(published_at, collected_at)`) + `veilles:rematch {--veille=}` ; câblé post-commit dans `VeilleService` (create/update-règles/réactivation) |

### M1.12 — Lecture des articles d'une veille (implémentée, auditée)

**Référence technique** : [`M1.12_Conception_Technique.md`](M1.12_Conception_Technique.md)
— conception autonome et complète, vérifiée contre le code réel et les
sources `ezyang/htmlpurifier` v4.19.1.

**État actuel** : **implémentée, testée, auditée, consolidée et
publiée** (commit `8e8b612` et précédents). Travaux réalisés en
sous-missions : M1.12.1 dépendance + cache, M1.12.2
`ImageUrlPolicy`, M1.12.3 `HtmlContentSanitizer`, M1.12.4 lecture
(query/contrôleur/routes/vues/CSP), M1.12.5 audit final + doc.

**Fichiers livrés** : `composer.json`/`composer.lock`
(`ezyang/htmlpurifier` **v4.19.1**, seule dépendance) ;
`app/Services/Articles/{ImageUrlPolicy,HtmlContentSanitizer,VeilleArticleQuery}.php` ;
`app/Http/Controllers/VeilleArticleController.php` ;
`app/Http/Middleware/ArticleCsp.php` ; `routes/web.php` (2 routes
`auth` + CSP) ; `resources/views/veilles/articles/{index,show}.blade.php`
+ lien « Articles » dans `veilles/show.blade.php` ;
`storage/app/.gitignore` (exception `!htmlpurifier/`) +
`storage/app/htmlpurifier/.gitignore` (contenu du cache ignoré) ;
`tests/Unit/{ImageUrlPolicyTest,HtmlContentSanitizerTest}.php` ;
`tests/Feature/VeilleArticlesTest.php`. Aucune migration.

**Sécurité appliquée** : isolation policy `view` + `user_id` du pivot
`article_veille` (jamais issu de la requête) ; association + rétention
6 mois vérifiées en une requête → **404** ; liste blanche validée des
paramètres (`source_id`, `keyword`, `page`, `"0"` conservé) ; HTML
assaini whitelist + post-passe DOM fail-closed ; URL d'images validées
(IP littérales avant IDN, noms locaux, DNS fail-closed, plafonds
50 images/25 hôtes) ; lien original http(s) uniquement, `target=_blank
rel="noopener noreferrer"` ; CSP complète sur les deux routes.
Limites résiduelles assumées et documentées : DNS rebinding /
split-horizon, redirections HTTP suivies par le navigateur (§6.3–§6.4
de la conception) — cohérentes avec le choix de chargement direct.

**Corrections d'audit (M1.12.5)** : schéma du lien original validé à
l'affichage (`javascript:` impossible dans `href`) ; `wherePivot`
via `when` produisait un where dynamique `"pivot" = ?` → filtres
appliqués directement sur la relation ; ordre de config purifier
(finalisation) et `null`-guard `maybeGetRawHTMLDefinition` corrigés
en M1.12.3.

**Tests** : 85 nouveaux tests ciblés (Unit : politique d'images 64,
sanitizer 21 ; Feature : 38 — isolation, 404, rétention, tri, filtres
combinés, `"0"`, paramètres malformés, sanitisation rendue, payloads
malveillants, fallback, prev/next filtrés, retour contexte, images
DNS-stub, CSP ×2) ; total **578/1640 bi-moteur**.

### M1.13.1 — État lu / non lu (implémenté, publié)

**Référence** : DataModel.md §3.9 et §4.D-bis/§4.E.

**État** : implémenté, testé, **commité et publié**
(`98b3897`/`423785d`/`6c181fa`/`f23c364`). `app/Models/ArticleUser.php` (PK composite
`(user_id, article_id)` — `setKeysForSaveQuery` comme `ArticleVeille`),
`database/factories/ArticleUserFactory.php`,
`app/Services/Articles/ArticleReadState.php` (`isRead`, `markRead`
upsert idempotent, `markUnread` = DELETE si ligne sans autre donnée
personnelle sinon `is_read=0`, `readMap` une requête), deux mutations
dans `VeilleArticleController` (`PUT`/`DELETE
/veilles/{veille}/articles/{article}/read`, mêmes garde-fous que la
lecture : policy + association + rétention → 404), badges Lu/Non lu +
formulaires `@method` dans les deux vues articles. `user_id` toujours
issu de l'utilisateur authentifié. Aucune migration.
13 tests Feature ciblés (isolation stricte, idempotence, préservation
favori/note, article_veille intacts, GET refusé, user_id injecté
ignoré).

### M1.13.2 — Favoris (implémenté, publié)

**Référence** : DataModel.md §3.9, §4.D-bis, §4.E.

**État** : implémenté, testé, **commité et publié** (mêmes commits
que M1.13.1). `ArticleUser::hasPersonalDataBesides()`
factorise la règle « ligne vide » (réutilisée par lu/non lu et
favoris) ; `app/Services/Articles/ArticleFavoriteState.php`
(`isFavorite`, `markFavorite` upsert, `removeFavorite` = DELETE si
plus aucune donnée personnelle sinon `is_favorite=0`, `favoriteMap`,
`favorites()` paginée par jointure `article_user` — accès autonome
§4.D-bis via `ArticlePersonalAccess::hasAccess`, correspondance
veille OU ligne `article_user` propre) ; mutations favoris dans `VeilleArticleController`
(mêmes garde-fous que la lecture) ; `FavoriteController` : `GET
/favoris` (liste paginée tri date effective DESC), `GET
/articles/{article}` (lecture autonome sanitisée + fallback),
PUT/DELETE `/articles/{article}/favorite` — 404 sans droit ;
vues `favorites/{index,show}` + toggles dans les pages veille + lien
« Mes favoris » dans la navigation. Un favori **protège de la purge**
(§4.E). 11 tests Feature (idempotence, isolation, préservation
note/catégorie/lu, survie à la suppression de correspondance, 404
article d'autrui, tri/pagination).

### M1.13.3 — Notes personnelles (implémenté, publié)

**Référence** : DataModel.md §3.9, §4.D-bis, §4.E.

**État** : implémenté, testé, **commité et publié** (mêmes commits
que M1.13.1).
`app/Services/Articles/ArticleNoteState.php` (`getNote`, `saveNote`
trim + upsert — vide/espaces = absence, `deleteNote` = NULL puis
DELETE si ligne vide via `hasPersonalDataBesides`) ; mutations note
PUT/DELETE sur les deux périmètres (`/veilles/{v}/articles/{a}/note`
mêmes garde-fous lecture ; `/articles/{a}/note` accès autonome
§4.D-bis → 404) ; carte « Ma note » (affichage échappé + textarea +
enregistrer/supprimer) dans `veilles/articles/show` et
`favorites/show`. Une note **protège de la purge** ; l'accès à
l'article survit à la disparition de la correspondance. 9 tests
Feature (CRUD, vide=absence, isolation stricte, préservation
lu/favori/catégorie, survie sans `article_veille`, anti-purge,
`user_id` injecté ignoré).

### M1.13.4 — Archivage personnel (implémenté, publié)

**Référence** : DataModel.md §3.9, §4.D-bis, §4.E.

**État** : implémenté, testé, **commité et publié** (mêmes commits
que M1.13.1).
`app/Services/Articles/ArticleArchiveState.php` (`isArchived`,
`archive` upsert, `unarchive` = DELETE si ligne vide via
`hasPersonalDataBesides` sinon `is_archived=0`, `archiveMap`,
`archived()` paginée) ; **masquage** des archives dans
`VeilleArticleQuery::base()` (`whereNotExists` `article_user` du
propriétaire → liste, filtres, navigation, `findArticle` → 404) et
dans `favorites()` — conforme §4.E (l'archive masque, ne protège pas
de la purge) ; `ArchiveController` : `GET /archives` (20/page, tri
date effective DESC) + PUT/DELETE `/articles/{a}/archive` via
`personalAccess->hasAccess` → 404 ; bouton « Archiver » dans la fiche veille,
toggle dans la fiche personnelle, page `archives/index` + lien nav
« Mes archives ». 12 tests Feature (idempotence, isolation, masquage
veille+favoris, survie sans `article_veille`, purgeable seul vs
protégé par favori/note, pagination, `user_id` injecté ignoré).

### M1.14.1 — Catégories : modèle + service (implémenté, publié)

**Référence** : DataModel.md §3.9/§3.10, §4.D-bis, §4.E, §4.F.

**État** : implémenté, testé, **commité et publié**
(`5f34c7a`/`d2c2664`/`c686cc4`/`d3a49eb`). `app/Models/Category.php`
(fillable `user_id`/`name`, relation `articleUsers()` — jointage sur
`category_id` suffisant car `UNIQUE(user_id,id)` + FK composite portent
le tenant), `database/factories/CategoryFactory.php`,
`app/Services/Articles/ArticleCategoryState.php` :
`categories(user)` (tri par nom + `articles_count` non archivés),
`create`/`rename` (trim, obligatoire, ≤100, `unique` scopé user +
`UniqueConstraintViolationException` → `ValidationException`, jamais de
fusion), `delete` (**transaction §4.F** : NULL-out des lignes du
propriétaire → élagage des lignes vidées → `DELETE`, RESTRICT en
garde-fou), `setCategory` (catégorie du user + `hasAccess` requis →
`ModelNotFoundException` sinon), `clearCategory` (NULL puis prune via
`hasPersonalDataBesides`). 12 tests Feature (unicité, cross-tenant
refusé, préservation des états, élagage, transaction, compteur
masqué-archivés). Aucune migration, aucun contrôleur/route/vue.

### M1.14.2 — Catégories : interface (implémenté, publié)

**État** : implémenté, testé, **commité et publié** (mêmes commits
que M1.14.1).
`app/Http/Controllers/CategoryController.php` : `GET/POST /categories`,
`PATCH/DELETE /categories/{category}` (`own()` → 404 cross-tenant),
`GET /categories/{category}/articles` (20/page, tri §4.E, archivés
masqués), `PUT/DELETE /articles/{a}/category` (`hasAccess` +
`ownCategory` → 404) ; mutations équivalentes dans
`VeilleArticleController` via `mutateArticle` ; `articlesInCategory`
+ `categoryIdOf` ajoutés au service ; vues `categories/index` (CRUD
inline + compteurs + erreurs `@error`) et `categories/articles`
(liste type favoris) ; sélecteur « Classer/Retirer » sur les deux
fiches article ; nav « Mes catégories ». 11 tests Feature (CRUD,
unicité, cross-tenant → 404, préservation états, masquage archivés,
pagination/tri, autonomie §4.D-bis, `user_id` injecté ignoré,
transaction de suppression).

M1.14.1→M1.14.3 : revue + consolidation Git effectuées et publiées
(`5f34c7a`/`d2c2664`/`c686cc4`/`d3a49eb`).

### M5.2 — Génération des digests (implémenté, publié)

**État** : implémenté, testé, **commité et publié** (`80a4eea`→
`4f3bc92`). Conception M5.1
verrouillée : curseur mécanique `article_veille.id` (ordre total,
égalités de timestamp impossibles), protocole de verrou `veilles`
FOR UPDATE rendant le watermark étanche (ordre de verrou = ordre
d'id = ordre de commit).

- Migrations : `article_veille.id BIGINT UNSIGNED AUTO_INCREMENT
  UNIQUE` (PK composite conservée ; id simulé par haute-eau sous
  SQLite dans `ArticleVeille::creating`) ; `veille_alerts` +
  `start_match_id`/`end_match_id` (curseurs, sans FK).
- `app/Services/ArticleVeilleWriter.php` : point unique d'écriture
  (`record`/`deleteFor`), verrou `veilles` avant toute écriture,
  conservé jusqu'au commit externe ; `KeywordMatcher` et
  `VeilleRematcher` adaptés — aucun autre chemin n'écrit.
- `app/Services/Alerts/DigestWindowCalculator.php` : ouverture =
  `end_match_id` du dernier `sent` (0 au 1er), watermark = MAX(id)
  sous verrou, sélection `id > open AND id <= watermark LIMIT 50`.
- `app/Models/VeilleAlert.php` + `alerts:generate` : par veille
  active+alertes → tx + verrou + garde `pending`/`processing` +
  `INSERT pending` (UNIQUE veille_id+digest_date absorbé en
  garde-fou) ; `digest_date` = jour civil Europe/Paris, `period_*`
  = affichage. Aucun digest vide, aucun envoi (M5.3+).

Tests : 14 Feature génération + 7 Feature concurrence **MariaDB
réelle** (connexion auxiliaire : générateur bloqué par le verrou
puis voyant la ligne commitée — le scénario de perte historique).

### M5.3 — Outbox, envoi et reprise (implémenté, publié)

**État** : implémenté, testé, **commité et publié** (mêmes commits
que M5.2).

- `app/Jobs/SendVeilleDigestJob.php` : réservation atomique
  `pending → processing` (bail 10 min, `attempts<3`, token UUID) ;
  envoi `VeilleDigestMail` au mail du propriétaire ; clôture `sent`
  + `sent_at` + `articles_count` **gardée par le token** ; échec →
  `pending` (<3) ou `failed` (≥3). Fenêtre `]start,end]` ∩ lignes
  vivantes ; 0 ligne → `sent` `articles_count=0`, **aucun e-mail
  vide**. Livraison au moins une fois (doublon possible si crash
  entre envoi et clôture — assumé).
- `app/Mail/VeilleDigestMail.php` + vues `emails/veille-digest`
  (HTML échappé) et `-text` : veille, période Europe/Paris, titre,
  source, date Paris, mot-clé, lien lecture interne + original.
- `alerts:distribute` (met les `pending` en file — n'envoie pas,
  idempotent via la réservation) et `alerts:sweep` (baux expirés
  redispatchés, `attempts>=3` expirés → `failed`).
- Scheduler : `alerts:generate` `dailyAt('06:30')->timezone(
  'Europe/Paris')`, `distribute` */5, `sweep` */10 — tous
  `withoutOverlapping`. Queue `database` drainée chaque minute
  (existant). Cron ISPConfig : `* * * * * php artisan schedule:run`
  (cf. Deployment.md).

Tests : 17 Feature outbox (Mail::fake) + scheduler — les tests de
concurrence MariaDB M5.2 couvrent déjà le verrou.

### M5.6 — Revue globale (effectuée, publiée)

M5.4 (mailable HTML+texte) et M5.5 (scheduler + doc) absorbés dans
M5.3. Revue croisée : **deux corrections mineures** —
`alerts:distribute` filtre désormais `attempts<3` (un `pending`
épuisé n'est plus redispatché avant le sweep) ; +3 tests
(distribute ignore les épuisées, job sur alerte supprimée,
réservation concurrente refusée sous verrou réel — seconde
connexion PDO). Chemins d'écriture `article_veille` audités :
`unsubscribe` ne fait que supprimer (sans risque pour le
watermark) ; tout insert/delete passe par `ArticleVeilleWriter`.

**Système complet** : `alerts:generate` (06:30 Europe/Paris,
verrou + curseur `article_veille.id`, ≤50) → `pending` →
`alerts:distribute`/`sweep` → `SendVeilleDigestJob` (réservation
atomique token, bail 10 min, 3 tentatives) → `VeilleDigestMail`
→ `sent`/`failed`. Livraison au moins une fois assumée.

M5 consolidé en 6 commits et publié (`80a4eea`→`4f3bc92`).

## 10. Prochaine étape proposée — préparation au déploiement

Le périmètre fonctionnel du MVP est désormais complet et publié.
La suite logique n'est plus une fonctionnalité mais la **mise en
production**, sous réserve des validations de Gaël :

1. **Audit de préparation au déploiement** : inventaire des
   prérequis restants (`.env` de production, `APP_DEBUG=false`,
   `MAIL_MAILER=sendmail`, caches `config:cache`/`route:cache`/
   `view:cache`, build Vite `npm ci && npm run build` sous Node 22
   nvm).
2. **Vérification de l'environnement de production** : PHP-FPM 8.3,
   extensions requises, permissions `storage/`, cron `schedule:run`
   déjà installé, délivrabilité mail (sendmail/Postfix, SPF/DKIM du
   domaine à confirmer par Gaël).
3. **Préparation de l'exposition `/web`** : procédure prête dans
   Deployment.md (index.php, .htaccess, symlinks `build`/`storage`)
   — aucune écriture dans `/web` hors procédure validée.
4. **Stratégie de migration de la base** : les **12 migrations
   métier** (dont les 2 M5) sont `Pending` sur `veille_db` — leur
   exécution nécessite **l'autorisation explicite de Gaël** ; ordre
   et rollback décrits dans MigrationPlan.md ; sauvegarde préalable
   à prévoir.
5. **Recette fonctionnelle** : checklist navigateur — inscription,
   ajout de source, collecte réelle, création de veille, lecture,
   favoris/notes/catégories/archives, activation `alerts_enabled`
   et réception d'un premier digest.

## 11. Règles de travail avec Devin

## 11. Règles de travail avec Devin

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
- Recette navigateur réelle : non exécutée (pas de navigateur
  dans l'environnement) — vérification manuelle requise au déploiement
  (voir §9 : liste, filtres, pagination, lecture, prev/next, Alpine.js
  sous CSP).

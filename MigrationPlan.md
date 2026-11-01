# MigrationPlan.md — Plan d'implémentation des migrations (Mission M1.6)

> Préparation technique des 10 migrations définies par `DataModel.md` v3.4.
> Aucune migration créée ici — document de spécification uniquement.
> Implémentation attendue sur **validation explicite** de Gaël.

## 1. État de l'existant (vérifié)

| Élément | État constaté |
|---|---|
| Migrations présentes | `0001_01_01_000000_create_users_table`, `..._create_cache_table`, `..._create_jobs_table` — style `return new class extends Migration`, `Schema::create` + `Blueprint` |
| Exécutées | Les 3, batch 1 (`migrate:status` ✓) — tables : `users`, `password_reset_tokens`, `sessions`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`, `migrations` |
| Base | `veille_db`, `veille_usr@localhost`, `ALL PRIVILEGES` sur `veille_db.*` uniquement — **aucun droit sur d'autres bases** |
| Connexion `mariadb` | `config/database.php` : driver `mariadb`, utf8mb4, `strict`, socket locale ; **pas de clé `timezone`** actuellement |
| Connecteur | `MySqlConnector` (hérité par `MariaDbConnector`) supporte `$config['timezone']` → `set time_zone='…'` à la connexion (vendor vérifié, ligne 110) |
| Tests | `phpunit.xml` : `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:` — insuffisant pour les tests de schéma MariaDB |
| Non-vide | La base n'est **pas vierge** — les nouvelles migrations doivent coexister avec les 9 tables existantes, sans les modifier |

## 2. Stratégie de migration

- **10 fichiers**, un par table, ordre §7 de `DataModel.md` (dépendances
  FK + index uniques parents **avant** les enfants).
- Nommage Laravel daté, ordre garanti par incrément :
  `2026_XX_XX_000001_create_categories_table` … `…_000010_create_veille_alerts_table`.
- Style identique aux migrations existantes : `return new class extends
  Migration`, `up()`/`down()`, `Schema::create`.
- `down()` : `Schema::dropIfExists` dans l'ordre inverse — les FK
  CASCADE/RESTRICT exigent la suppression enfant d'abord.
- **Aucun `DB::statement` requis** : tous les besoins sont couverts par le
  Schema Builder (cf. §4 — justification détaillée).
- `utf8mb4`/`utf8mb4_unicode_ci` : défaut de la connexion, rien à déclarer.

## 3. Détail des 10 migrations

> Types Laravel ↔ MariaDB explicites. `timestamp(6)` = `$table->timestamp('x', 6)`,
> `$table->timestamps(6)` pour les paires created_at/updated_at.

### M1 — `categories`

```php
$table->id();
$table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
$table->string('name', 100);
$table->timestamps();
$table->unique(['user_id', 'name']);
$table->unique(['user_id', 'id']);      // parent des FK composites (article_user)
$table->index('user_id');
```
- `INDEX(user_id)` déclaré conformément à DataModel §3.10 (fonctionnellement
  redondant avec `UNIQUE(user_id,name)` en préfixe gauche, mais explicité
  par le modèle — conservé pour traçabilité).

### M2 — `sources`

```php
$table->id();
$table->string('feed_url', 512)->unique();
$table->string('name');
$table->string('site_url', 512)->nullable();
$table->string('description', 500)->nullable();
$table->boolean('is_catalogue')->default(false);
$table->boolean('is_active')->default(true);
$table->string('etag')->nullable();
$table->string('last_modified')->nullable();
$table->timestamp('last_fetched_at')->nullable();
$table->timestamp('last_success_at')->nullable();
$table->unsignedSmallInteger('fail_count')->default(0);
$table->string('last_error', 500)->nullable();
$table->timestamp('orphaned_at')->nullable();
$table->timestamps();
$table->index(['is_active', 'last_fetched_at']);
$table->index('is_catalogue');
$table->index('orphaned_at');
```
- `feed_url` varchar(512) : 2048 octets utf8mb4 < 3072 — UNIQUE OK.

### M3 — `user_sources`

```php
$table->id();
$table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
$table->foreignId('source_id')->constrained('sources')->cascadeOnDelete();
$table->string('custom_name')->nullable();
$table->timestamp('created_at');        // pas d'updated_at (DataModel §3.3)
$table->unique(['user_id', 'source_id']);
$table->index('source_id');
```

### M4 — `veilles`

```php
$table->id();
$table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
$table->string('name');
$table->string('description', 500)->nullable();
$table->boolean('is_active')->default(true);
$table->boolean('alerts_enabled')->default(false);
$table->timestamps(6);                  // timestamp(6) — borne initiale digest
$table->unique(['user_id', 'name']);
$table->unique(['id', 'user_id']);      // parent des FK composites enfants
$table->index('user_id');
$table->index(['alerts_enabled', 'is_active']);
```

### M5 — `veille_keywords`

```php
$table->id();
$table->unsignedBigInteger('veille_id');
$table->unsignedBigInteger('user_id');  // tenant dénormalisé
$table->string('term');
$table->timestamps();
$table->foreign(['veille_id', 'user_id'])
      ->references(['id', 'user_id'])->on('veilles')->cascadeOnDelete();
$table->unique(['veille_id', 'term']);
$table->index(['veille_id', 'user_id']); // index enfant explicite de la FK
$table->index('user_id');
```

### M6 — `source_veille`

```php
$table->unsignedBigInteger('veille_id');
$table->unsignedBigInteger('source_id');
$table->unsignedBigInteger('user_id');
$table->primary(['veille_id', 'source_id']);
$table->foreign(['veille_id', 'user_id'])
      ->references(['id', 'user_id'])->on('veilles')->cascadeOnDelete();
$table->foreign('source_id')->references('id')->on('sources')->cascadeOnDelete();
$table->index('source_id');              // FK enfant + lookup inverse matching
$table->index(['veille_id', 'user_id']); // FK composite enfant
```
- Pas de colonne `id`, pas de timestamps — pivot pur (DataModel §3.6).

### M7 — `articles`

```php
$table->id();
$table->foreignId('source_id')->constrained('sources')->restrictOnDelete();
$table->string('guid', 512)->nullable();
$table->char('dedup_key', 64);
$table->string('url', 2048);
$table->char('url_hash', 64);
$table->char('content_hash', 64)->nullable();
$table->string('title', 512);
$table->string('author')->nullable();
$table->text('summary')->nullable();
$table->longText('content')->nullable();
$table->timestamp('published_at')->nullable();
$table->timestamp('collected_at');
$table->timestamps();
$table->unique(['source_id', 'dedup_key']);
$table->index('source_id');
$table->index(['source_id', 'url_hash']);
$table->index(['source_id', 'published_at']);
$table->index('published_at');
$table->index('collected_at');
```
- `restrictOnDelete()` : Laravel ≥10 le supporte ; sinon `->onDelete('restrict')`.
- `INDEX(source_id)` déclaré conformément à DataModel §3.7 (redondant avec
  `UNIQUE(source_id,dedup_key)` en préfixe gauche, explicité par le modèle).

### M8 — `article_veille`

```php
$table->unsignedBigInteger('veille_id');
$table->unsignedBigInteger('article_id');
$table->unsignedBigInteger('user_id');
$table->string('matched_keyword')->nullable();
$table->timestamp('created_at', 6);      // timestamp(6) — bornes digest
$table->primary(['veille_id', 'article_id']);
$table->foreign(['veille_id', 'user_id'])
      ->references(['id', 'user_id'])->on('veilles')->cascadeOnDelete();
$table->foreign('article_id')->references('id')->on('articles')->cascadeOnDelete();
$table->index('article_id');
$table->index(['veille_id', 'user_id']);
$table->index(['veille_id', 'created_at']);
```

### M9 — `article_user`

```php
$table->unsignedBigInteger('user_id');
$table->unsignedBigInteger('article_id');
$table->unsignedBigInteger('category_id')->nullable();
$table->boolean('is_read')->default(false);
$table->boolean('is_favorite')->default(false);
$table->boolean('is_archived')->default(false);
$table->text('note')->nullable();
$table->timestamps();
$table->primary(['user_id', 'article_id']);
$table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
$table->foreign('article_id')->references('id')->on('articles')->cascadeOnDelete();
$table->foreign(['user_id', 'category_id'])
      ->references(['user_id', 'id'])->on('categories')->restrictOnDelete();
$table->index('article_id');
$table->index(['user_id', 'category_id']); // FK composite enfant + navigation
$table->index(['user_id', 'is_favorite']);
```
- `category_id` NULLABLE : FK composite non évaluée quand une composante
  est NULL → « non classé » exempté du contrôle (comportement InnoDB voulu).
- **RESTRICT** volontaire : la suppression de catégorie est une
  transaction applicative (`UPDATE … SET category_id=NULL` puis DELETE).

### M10 — `veille_alerts`

```php
$table->id();
$table->unsignedBigInteger('veille_id');
$table->unsignedBigInteger('user_id');
$table->date('digest_date');
$table->enum('status', ['pending','processing','sent','failed'])->default('pending');
$table->timestamp('period_start', 6)->nullable();
$table->timestamp('period_end', 6)->nullable();
$table->unsignedSmallInteger('articles_count')->default(0);
$table->unsignedTinyInteger('attempts')->default(0);
$table->timestamp('reserved_at', 6)->nullable();
$table->char('reservation_token', 36)->nullable();
$table->string('error', 500)->nullable();
$table->timestamp('sent_at', 6)->nullable();
$table->timestamps();
$table->unique(['veille_id', 'digest_date']);
$table->index(['veille_id', 'status', 'reserved_at']);
$table->index(['user_id', 'sent_at']);
$table->index(['veille_id', 'user_id']); // index enfant explicite de la FK composite
$table->foreign(['veille_id', 'user_id'])
      ->references(['id', 'user_id'])->on('veilles')->cascadeOnDelete();
```
- `reservation_token` volontairement **non indexé** (contrôle d'écriture
  via PK `id` + condition — DataModel §3.11).
- `enum()` Laravel → `ENUM` natif MariaDB (pas de varchar+check).

## 4. Instructions SQL spécifiques (`DB::statement`)

**Aucune requise.** Vérification point par point :

| Besoin | Support Schema Builder | Verdict |
|---|---|---|
| PK composites | `$table->primary([...])` | natif |
| FK composites | `$table->foreign([...])->references([...])` | natif |
| `timestamp(6)` | `$table->timestamp('x', 6)`, `timestamps(6)` | natif |
| `ENUM` | `$table->enum('status', [...])` | natif |
| `char(36/64)` | `$table->char('x', n)` | natif |
| RESTRICT | `restrictOnDelete()` | natif (≥10) |
| Index composites/uniques | `index`/`unique` tableaux | natif |

Rien ne justifie du SQL brut ; si un nom de contrainte généré dépassait 64
caractères (limite MariaDB), nommer l'index explicitement
(`->unique([...], 'nom_court')`) — à surveiller, non anticipé.

## 5. Configuration UTC (fuseau de session)

- Le connecteur MariaDB exécute `set time_zone='<valeur>'` à chaque
  connexion si `timezone` est défini (mécanisme natif vérifié).
- Ajout prévu dans `config/database.php`, bloc `mariadb` :
  `'timezone' => env('DB_TIMEZONE', '+00:00')`
- `.env` : `DB_TIMEZONE="+00:00"` (valeur explicite, pas de secret).
- **Vérification** (implémentation + tests) :
  `SELECT @@session.time_zone` → `'+00:00'` ; test dédié comparant
  `NOW()`/`UTC_TIMESTAMP(6)` sur une insertion/relire.
- Aucune modification des colonnes `TIMESTAMP` en `DATETIME` — la session
  résout l'ambiguïté de conversion.

## 6. Plan de tests

**Prérequis bloquant** : les tests doivent cibler une vraie MariaDB 10.11 —
sqlite ne vérifie ni FK composites, ni `timestamp(6)`, ni `time_zone`.
`veille_usr` n'a de droits que sur `veille_db.*` → création d'une base de
test dédiée **`veille_test`** (grant `veille_usr` dessus) requise via
ISPConfig/admin — action de Gaël, hors périmètre de cette mission.

- **Configuration de test** : fichier séparé **`phpunit.mariadb.xml`**
  (documenté ici, créé à l'implémentation) — le `phpunit.xml` sqlite
  actuel reste la configuration par défaut pour les tests sans DB.
  Contenu prévu, miroir de `phpunit.xml` avec le bloc `<php>` :

  ```xml
  <env name="DB_CONNECTION" value="mariadb"/>
  <env name="DB_HOST" value="localhost"/>
  <env name="DB_PORT" value="3306"/>
  <env name="DB_DATABASE" value="veille_test"/>
  <env name="DB_USERNAME" value="veille_usr"/>
  <env name="DB_TIMEZONE" value="+00:00"/>
  ```

  **`DB_PASSWORD` ne figure jamais dans `phpunit.mariadb.xml`** — ni en
  clair, ni par référence : il est hérité de l'environnement du processus
  PHP (export shell avant lancement) ou fourni par le `.env` non
  versionné. Aucun secret n'est inscrit dans un fichier de configuration
  commité.
- **Lancement** :

  ```bash
  export DB_PASSWORD='mot_de_passe'
  php artisan test --configuration phpunit.mariadb.xml
  ```

  (ou `vendor/bin/phpunit -c phpunit.mariadb.xml`) ; `RefreshDatabase`
  pour l'isolation par test ; les tests de schéma/comportement MariaDB
  sont regroupés dans un testsuite dédié.
- **Tests de schéma** (via `Schema::hasTable/Column` + `SHOW INDEX` /
  `information_schema.STATISTICS` & `KEY_COLUMN_USAGE`) :
  - les 10 tables existent avec leurs colonnes et types attendus ;
  - PK composites présentes (`source_veille`, `article_veille`,
    `article_user`) ;
  - uniques : `feed_url`, `(user_id,name)×2`, `(veille_id,term)`,
    `(veille_id,source_id)`, `(source_id,dedup_key)`, `(user_id,article_id)`,
    `(veille_id,digest_date)`, parents composites `(id,user_id)` et
    `(user_id,id)` ;
  - index composites explicites présents (§6 de DataModel) ;
  - `status` ENUM, `timestamp(6)` précisés (information_schema
    `DATETIME_PRECISION=6`), `char(36)`/`char(64)`.
- **Tests comportementaux** :
  - FK composite rejetant un `category_id` d'un autre utilisateur ;
  - `category_id=NULL` accepté ;
  - CASCADE `users→veilles→enfants`, `articles→article_*` ;
  - RESTRICT `sources.articles`, `article_user→categories` (delete refusé
    avant nullification) ;
  - précision microseconde : insert `article_veille.created_at` avec µs
    et comparaison stricte aux bornes ;
  - `@@session.time_zone = '+00:00'` via connexion Laravel ;
  - protocole de réservation `FOR UPDATE` + `reservation_token` (deux
    réservations concurrentes → une seule gagne).
- Exécution : `php artisan test` (suite existante sqlite conservée pour
  les tests sans DB) + groupe dédié MariaDB.

## 7. Risques et points de vigilance

1. **`veille_test` inexistante** — prérequis externe ; sans elle, les
   tests de schéma resteront partiels (documenté, pas bloquant pour
   l'écriture des migrations).
2. **Noms d'index/contraintes > 64 caractères** — surveiller les noms
   générés sur composites ; nommer explicitement si dépassement.
3. **FK composite + `restrictOnDelete`** — vérifier à l'implémentation que
   MariaDB accepte la combinaison (référence non-unique tolérée côté
   enfant, index requis des deux côtés) ; les tests M1.6 prévus la valident.
4. **`timestamps(6)` sur `veilles`** : `created_at` sert de borne initiale
   de `period_start` — ne pas oublier la précision 6 (oubli = borne
   tronquée à la seconde, nouveautés bornées potentiellement dupliquées).
5. **Enums** : ajout futur d'une valeur `status` = `ALTER TABLE` (migration
   dédiée) — évolutivité documentée, pas de changement de type.
6. **Ordre des migrations** : strictement §7 — les index uniques parents
   (`veilles(id,user_id)`, `categories(user_id,id)`) doivent exister avant
   les FK composites des migrations 5, 6, 8, 9, 10.
7. **`down()`** : respecter l'ordre inverse des FK ; sur `veille_db`
   réelle, tout rollback = opération manuelle encadrée (pas de
   `migrate:fresh` — interdiction AGENTS.md).
8. **Protocole outbox** : la réservation `FOR UPDATE` + jeton n'est pas
   testable en unitaire SQLite — prévu dans le plan MariaDB §6.

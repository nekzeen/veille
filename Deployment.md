# Déploiement

> Aucune modification du vhost Apache généré par ISPConfig n'est autorisée.
> Toute l'exposition passe par le docroot existant `/web` et `.htaccess`.

## Prérequis — actions panneau ISPConfig (à faire par l'administrateur)

| # | Action | Statut |
|---|---|---|
| 1 | Créer une base MariaDB + utilisateur dédié pour `veille` | ⬜ à faire |
| 2 | Créer le cron : `* * * * * php /var/www/clients/client2/web18/private/veille/artisan schedule:run >> /dev/null 2>&1` | ⬜ à faire (prérequis M1) |
| 3 | Vérifier que le site est déclaré en PHP 8.3 dans le panneau | ⬜ à vérifier (le pool FPM actif est déjà 8.3) |
| 4 | Confirmer SPF/DKIM/DMARC du domaine pour les e-mails sortants | ⬜ à vérifier (M5) |

> Le cron `schedule:run` devient nécessaire **dès que des tâches planifiées
> sont utilisées** (collecte de flux, workers de queue, alertes — à partir de
> M2). Sa création est anticipée en prérequis M1 car elle relève d'une action
> manuelle dans le panneau ISPConfig.

## Topologie

- **Code applicatif** : `/private/veille` (hors docroot, inclut `.env`,
  `vendor/`, `storage/`).
- **Docroot** : `/var/www/clients/client2/web18/web` — contient uniquement
  le front controller, `.htaccess` et les assets.

`/web` contient des fichiers gérés par ISPConfig (`error/`, `stats/`,
`robots.txt`, `favicon.ico`, `standard_index.html`). On ne les supprime pas ;
`index.php` prend le pas grâce à la priorité `DirectoryIndex`.

## Exposition dans `/web`

### `/web/index.php`

```php
<?php

use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

$app = dirname(__DIR__).'/private/veille';

if (file_exists($maintenance = $app.'/storage/framework/maintenance.php')) {
    require $maintenance;
}

require $app.'/vendor/autoload.php';

(require_once $app.'/bootstrap/app.php')
    ->handleRequest(Request::capture());
```

### `/web/.htaccess`

```apache
Options -Indexes

<IfModule mod_rewrite.c>
    RewriteEngine On

    # Force HTTPS
    RewriteCond %{HTTPS} off
    RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]

    # Front controller Laravel
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteRule ^ index.php [L]
</IfModule>
```

### Assets

```bash
ln -sfn /var/www/clients/client2/web18/private/veille/public/build \
        /var/www/clients/client2/web18/web/build
ln -sfn /var/www/clients/client2/web18/private/veille/public/storage \
        /var/www/clients/client2/web18/web/storage
```

`Options +SymlinksIfOwnerMatch` est actif : lien et cible appartiennent tous
deux à `web18` → autorisé. (`public/storage` lui-même est créé par
`php artisan storage:link`.)

## Procédure de déploiement (manuelle, M1)

```bash
cd /var/www/clients/client2/web18/private/veille

php artisan down || true
git pull --ff-only
composer install --no-dev --optimize-autoloader

# Build assets (sous nvm Node 22)
source ~/.nvm/nvm.sh && nvm use 22
npm ci && npm run build

php artisan migrate --force
php artisan storage:link          # idempotent
php artisan config:cache route:cache view:cache
php artisan queue:restart         # signal aux workers cron

php artisan up
```

Un script `deploy.sh` à la racine du projet sera créé en M1 pour enchaîner
ces étapes avec arrêt sur erreur (`set -euo pipefail`).

## Vérifications post-déploiement

- `curl -sI https://veille.gaelmorvan.fr` → 200.
- `curl -sI http://veille.gaelmorvan.fr` → 301 vers HTTPS.
- `https://veille.gaelmorvan.fr/.env` → 404.
- `https://veille.gaelmorvan.fr/storage/../.env` → 404.
- Pages d'erreur : `APP_DEBUG=false` → aucun stack trace exposé.
- `php artisan about` : environnement, cache et drivers conformes.
- `~/log/error.log` sans nouvelle erreur PHP.

## Rollback

- Code : `git checkout <tag-ou-commit>` puis re-run des caches
  (`config:cache route:cache view:cache`) et `npm run build` si nécessaire.
- Base : `php artisan migrate:rollback --step=N` **uniquement** si la
  migration est réversible et validée — sinon restaurer depuis sauvegarde.
- Les tags semver (`v0.1.0`, …) marquent les états déployables.

## Sauvegardes

- `backup/` existe au niveau du site (géré par ISPConfig) — politique exacte
  **à vérifier dans le panneau**.
- Recommandation : dump `mysqldump` quotidien de la base `veille` vers
  `private/` (hors webroot) via cron, en complément de la sauvegarde
  système — à mettre en place lors de M1/M2.

## Éléments restant à vérifier

- Existence/permissions d'un crontab utilisateur `web18` si le cron panneau
  n'est pas disponible (fallback non testé).
- Capacités Passenger pour un éventuel runtime Node — **non retenu**
  (pas de Node en production, décision actée).
- Redis/Memcached présents mais **non retenus** (mutualisés, sans auth ;
  drivers `database` choisis).
- FQDN mail sortant et réputation (premier envoi réel en M5).

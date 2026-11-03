# Production Runbook — Veille

> Procédure globale de mise en production, consolidée depuis :
> `Backup_Restore.md`, `Production_Configuration.md`,
> `Web_Exposure.md`, `Production_Migrations.md`.
> **Aucune opération n'est exécutée par ce document.** Chaque étape
> marquée 🔒 exige l'autorisation explicite de Gaël.

## 0. Prérequis confirmés (audits M6)

- PHP 8.3.36, Composer 2.5.5, Node 22.23.4 (nvm), MariaDB 10.11.19.
- Cron ISPConfig `/etc/cron.d/ispc_chrooted_web18` → `schedule:run`
  chaque minute sous `web18` — **existant, validé, ne pas recréer**.
- Apache : `AllowOverride All`, `Options +SymlinksIfOwnerMatch`,
  FPM `/var/lib/php8.2-fpm/web18.sock`, HTTPS :443.
- `veille_db` : 3 migrations framework Ran, **12 métier Pending** —
  tables vides.
- Outils backup : `mariadb-dump`, `tar`, `gzip` — 245 GB libres.

## Étape 0 — État Git (préparation)

```bash
cd /var/www/clients/client2/web18/private/veille
git status -sb          # référence attendue, working tree propre
git log -1 --oneline    # commit à déployer — noter le SHA
```

**Arrêt si** : modifications non commitées non validées ou SHA non
confirmé par Gaël.

## Étape 1 — Sauvegarde (avant toute opération)

```bash
BK=/var/www/clients/client2/web18/private/backups/veille/$(date +%Y%m%d-%H%M)
mkdir -p "$BK" && chmod 700 /var/www/clients/client2/web18/private/backups
mariadb-dump --single-transaction --triggers --tz-utc \
  --default-character-set=utf8mb4 veille_db | gzip > "$BK/veille_db.sql.gz"
tar czf "$BK/app.tar.gz" --exclude='veille/.env' --exclude='veille/.env.*' \
  --exclude='veille/.git' --exclude='veille/vendor' \
  --exclude='veille/node_modules' --exclude='veille/public/build' \
  --exclude='veille/bootstrap/cache' \
  --exclude='veille/storage/framework/cache' \
  -C /var/www/clients/client2/web18/private veille
cp -p .env "$BK/env.bak" && chmod 600 "$BK/env.bak"
tar czf "$BK/web.tar.gz" -C /var/www/clients/client2/web18 web
git rev-parse HEAD > "$BK/git-sha.txt"

# Intégrité (Backup_Restore.md §4)
gzip -t "$BK/veille_db.sql.gz"
zcat "$BK/veille_db.sql.gz" | tail -1        # "Dump completed"
tar tzf "$BK/app.tar.gz" | grep -E '(^|/)\.env($|\.)'   # → vide
```

**Arrêt si** : dump incomplet, `.env` dans l'archive, espace disque
insuffisant, privilège `mariadb-dump` refusé (à confirmer au 1er dump).

## Étape 2 — Configuration production 🔒

```bash
cp -p .env .env.pre-prod
# modifier .env :
#   APP_ENV=production   APP_DEBUG=false   LOG_LEVEL=info
#   DB_TIMEZONE=+00:00   MAIL_SENDMAIL_PATH="/usr/sbin/sendmail -bs"
#   MAIL_MAILER reste "log" à cette étape — sendmail = étape 9
php artisan config:clear
```

**Arrêt si** : incohérence `.env` (APP_KEY absente/modifiée, DB inatteignable).

## Étape 3 — Build production

```bash
composer install --no-dev --optimize-autoloader
source ~/.nvm/nvm.sh && nvm use 22 && npm ci && npm run build
php artisan storage:link
```

**Arrêt si** : échec build ou `public/build/manifest.json` absent.

## Étape 4 — Exposition `/web` 🔒

```bash
# préparer dans une zone temporaire, puis :
cp <index.php adapté chemins absolus> /var/www/clients/client2/web18/web/index.php
cp <.htaccess + DirectoryIndex index.php> /var/www/clients/client2/web18/web/.htaccess
ln -s /var/www/clients/client2/web18/private/veille/public/build \
      /var/www/clients/client2/web18/web/build
```

Fichiers ISPConfig (`error/`, `stats/`, `standard_index.html`,
`favicon.ico`, `robots.txt`) **préservés**. Détails : Web_Exposure.md §3.

**Arrêt si** : symlink rejeté (SymlinksIfOwnerMatch), `DirectoryIndex`
inefficace, erreur 500 au chargement.

## Étape 5 — Migrations métier 🔒🔒

**Double autorisation** (sauvegarde déjà faite à l'étape 1) :

```bash
php artisan migrate:status              # 12 Pending confirmés
php artisan migrate --force
php artisan migrate:status              # 15 Ran attendus
```

Contrôles : `SHOW TABLES` ≈ 21 tables ; FK via `information_schema` ;
`php artisan test` + `-c phpunit.mariadb.xml` verts.

**Incident** : Production_Migrations.md §4 — jamais de rollback auto ;
analyse d'état réel puis restauration dump si nécessaire.

## Étape 6 — Caches Laravel

```bash
php artisan config:cache route:cache view:cache
php artisan optimize
```

**Arrêt si** : `config:cache` échoue (`.env` malformé, APP_KEY invalide).

## Étape 7 — Contrôle HTTPS et assets

```bash
curl -Is https://veille.gaelmorvan.fr          # 302 → login
curl -s  https://veille.gaelmorvan.fr/.env     # 404/403
curl -s  https://veille.gaelmorvan.fr/.git/config   # 404
curl -s  https://veille.gaelmorvan.fr/vendor/autoload.php  # 404
curl -sI https://veille.gaelmorvan.fr/build/assets/<fichier>.css  # 200
```

**Arrêt si** : 500, `.env` accessible, assets 404 (symlink cassé).

## Étape 8 — Vérification applicative 🔒 (avec Gaël)

Recette navigateur (REPRISE_PROJET.md §10 / Web_Exposure.md §7) :
inscription, source, collecte (`feeds:fetch` manuel), veille, lecture,
états personnels, `schedule.log` alimenté (cron ISPConfig existant).

**Arrêt si** : erreur fonctionnelle non expliquée.

## Étape 9 — Activation messagerie 🔒

```bash
# .env : MAIL_MAILER=sendmail
php artisan config:cache
# veille avec alerts_enabled + alerts:generate manuel + queue:work
# → digest reçu ; SPF/DKIM vérifiés (action DNS manuelle hors applicatif)
```

**Arrêt si** : non-réception — vérifier `veille_alerts`, `laravel.log`,
Postfix, SPF/DKIM avant toute nouvelle tentative.

## Retour arrière par couche

| Échec à | Action |
|---|---|
| Étape 1–3 | `.env.pre-prod` + `config:clear` — réversible |
| Étape 4 | retirer `index.php`/`.htaccess`/`build` → placeholder ISPConfig |
| Étape 5 | restauration dump (Backup_Restore.md §5 — **données post-dump perdues**) |
| Étape 6+ | `config:clear` + état précédent |
| Globale | `private/veille.prev`/`web.tar.gz`/dump — jamais de suppression aveugle |

## Autorisations récapitulatives (Gaël)

1. 🔒 Modification `.env` (étapes 2, 9) — `APP_KEY` préservée.
2. 🔒 Écriture `/web` (étape 4).
3. 🔒🔒 `migrate --force` sur `veille_db` (étape 5).
4. 🔒 Activation `sendmail` + premier envoi réel (étape 9).
5. Manuel : SPF/DKIM DNS, sauvegardes, DROP éventuel en restauration.

## Opérations **jamais** réalisées

`git stash`/push non validé · `migrate:fresh`/`db:wipe`/`rollback`
auto · `rm -rf` en restauration · `DROP DATABASE` automatique ·
modification vhost/pool/ISPConfig · nouveau cron ·
DriveOptimizer.

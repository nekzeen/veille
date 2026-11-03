# Web Exposure — Exposition de Laravel dans `/web`

> Procédure préparée (M6.7) — **non appliquée**. Activation soumise à
> la décision de Gaël. Aucun vhost, pool FPM ni ISPConfig modifié.

## 1. État actuel

| Élément | Constaté |
|---|---|
| Application | `/var/www/clients/client2/web18/private/veille` |
| DocumentRoot | `/var/www/clients/client2/web18/web` — fichiers ISPConfig : `error/`, `stats/`, `standard_index.html`, `favicon.ico`, `robots.txt` |
| `public/` Laravel | `index.php`, `.htaccess`, `favicon.ico`, `robots.txt`, `build/` (assets Vite compilés) |
| Vhost | `DocumentRoot /var/www/clients/client2/web18/web`, `ServerName veille.gaelmorvan.fr`, FPM `web18.sock`, Suexec `web18:client2`, HTTPS :443 TLS≥1.2 |
| Directives Apache | `AllowOverride All` + `Options +SymlinksIfOwnerMatch` sur `/web` → `.htaccess` et symlinks **autorisés** (propriétaire `web18` identique lien→cible) |
| `index.php` Laravel | chemins relatifs `__DIR__.'/../storage/...'`, `__DIR__.'/../vendor/...'` — **inutilisable tel quel hors `public/`** |

## 2. Architecture cible

```
/private/veille          ← application complète (.env, vendor, storage, app — HORS docroot)
    public/build/        ← assets Vite compilés

/web                     ← DocumentRoot ISPConfig
    index.php            ← front controller adapté (chemins absolus → private/veille)
    .htaccess            ← rewrite Laravel standard
    build → /var/www/clients/client2/web18/private/veille/public/build   (symlink)
    error/, stats/, standard_index.html, favicon.ico, robots.txt   ← ISPConfig, PRÉSERVÉS
```

**Principe** : seuls `index.php` + `build` sont visibles. Toute
requête non-fichier est routée par `.htaccess` vers `index.php`, qui
bootstrap l'application via chemins absolus.

## 3. Fichiers proposés

### `/web/index.php`

Adaptation minimale du front controller Laravel 12 — chemins relatifs
`__DIR__.'/../…'` remplacés par des **chemins absolus** :

```php
<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

$base = '/var/www/clients/client2/web18/private/veille';

if (file_exists($maintenance = $base.'/storage/framework/maintenance.php')) {
    require $maintenance;
}

require $base.'/vendor/autoload.php';

/** @var Application $app */
$app = require_once $base.'/bootstrap/app.php';

$app->handleRequest(Request::capture());
```

### `/web/.htaccess`

Copie de `private/veille/public/.htaccess` **+ une ligne en tête** :

```apache
DirectoryIndex index.php
```

**Obligatoire** : `dir.conf` global ordonne
`index.html index.cgi index.pl index.php index.xhtml index.htm` —
`index.html` prime sur `index.php`, donc `standard_index.html`
(ISPConfig) serait servi sur `/` à la place de Laravel. La directive
en `.htaccess` est autorisée (`AllowOverride All`) et ne touche
aucun fichier ISPConfig.

### `/web/build` — lien symbolique

```bash
ln -s /var/www/clients/client2/web18/private/veille/public/build \
      /var/www/clients/client2/web18/web/build
```

`Options +SymlinksIfOwnerMatch` est actif : lien `web18` → cible
`web18` ✓. Manifest Vite lu via `public/build` — le chemin réel est
conservé, aucune URL modifiée.

**Traversée vérifiée** (`namei`, perms réelles) :
- `private/` `drwx--x---` (701) : groupe `client2` a `--x` — **`www-data`
  est membre de `client2`** (gid 5008, `id www-data` confirmé) →
  Apache traverse sans droit de lecture du contenu privé ;
- `veille/`, `public/`, `build/`, `build/assets/` tous `755`,
  fichiers `644` → assets servis directement par Apache, pas via FPM ;
- `web18/` `755` → tout le chemin est franchissable.

**Non nécessaire** : symlink `storage` (aucun stockage public —
`FILESYSTEM_DISK=local`, pas d'avatars). À ajouter uniquement si une
fonctionnalité l'exige.

## 4. Sécurité

| Risque | Protection |
|---|---|
| Accès `.env`/`vendor`/`storage` | hors docroot — impossible par construction ; `Deny` inutile |
| Traversal `/../` | PHP open_basedir non requis — l'app est hors webroot ; Apache refuse par défaut |
| `.git` | hors docroot ✓ |
| Symlink hors root | `SymlinksIfOwnerMatch` empêche les liens vers fichiers d'autres propriétaires |
| Indexation | `Options -Indexes` dans le `.htaccess` Laravel (mod_negotiation) |
| Fichiers de sauvegarde | `backups/` dans `private/` — hors docroot |
| Erreurs PHP | `APP_DEBUG=false` en prod (Production_Configuration.md) |
| `standard_index.html` | restera à la racine — le `DirectoryIndex` doit privilégier `index.php` ; si `index.html` prime, renommer le fichier ISPConfig **interdit** → vérifier `DirectoryIndex` effectif au déploiement |

## 5. Bascule (ordonnée, réversible)

**Inventaire préexistants ISPConfig** (à ne jamais supprimer) :
`error/`, `stats/`, `standard_index.html`, `favicon.ico`, `robots.txt`.
**Artefacts ajoutés** : `index.php`, `.htaccess`, `build` (symlink).

```bash
# 0. Sauvegarde du docroot actuel
tar czf ~/web_pre_deploy_$(date +%Y%m%d).tgz -C /var/www/clients/client2/web18 web

# 1. Zone temporaire (même FS)
mkdir /var/www/clients/client2/web18/private/web-new
# y préparer index.php adapté + .htaccess (avec DirectoryIndex index.php)

# 2. Bascule contrôlée — standard_index.html PRÉSERVÉ (inerte une fois
#    DirectoryIndex index.php en place)
cp /var/www/clients/client2/web18/private/web-new/* /var/www/clients/client2/web18/web/
ln -s /var/www/clients/client2/web18/private/veille/public/build /var/www/clients/client2/web18/web/build

# 3. Vérifications
curl -Is https://veille.gaelmorvan.fr | head -1      # 200/302
curl -s https://veille.gaelmorvan.fr/.env | head -1   # doit être 404/403
curl -sI https://veille.gaelmorvan.fr/build/assets/*.css | head -1  # 200
```

**Échec à mi-parcours** : `/web` reste dans son état précédent tant que
`index.php` n'est pas posé — les fichiers ISPConfig sont préservés ;
si `index.php` est déjà posé, `rm web/index.php web/.htaccess web/build`
restaure l'index statique ISPConfig (`web.tar.gz` en secours).

## 6. Retour arrière

```bash
# Suppression des SEULS artefacts ajoutés — aucun fichier ISPConfig touché
rm /var/www/clients/client2/web18/web/index.php \
   /var/www/clients/client2/web18/web/.htaccess \
   /var/www/clients/client2/web18/web/build
# en cas de doute sur l'intégrité du docroot :
tar xzf ~/web_pre_deploy_*.tgz -C /var/www/clients/client2/web18/
```

Réversible intégralement — l'application `private/` n'est pas touchée,
`standard_index.html` n'a jamais été déplacé ni supprimé : le site
redevient le placeholder ISPConfig dès le retrait de `index.php` et
`.htaccess` (le `DirectoryIndex` global repasse sur `index.html`).

## 7. Checklist de validation

- [ ] `GET /` → 302 login / 200
- [ ] `GET /.env` → 404/403
- [ ] `GET /vendor/...` → 404
- [ ] `GET /storage/...` → 404
- [ ] `GET /build/assets/<hash>.css` → 200 (manifest trouvé)
- [ ] `GET /.git/config` → 404
- [ ] `GET /error/` → ISPConfig conservé
- [ ] `php artisan` CLI non affecté
- [ ] `storage/logs/laravel.log` sans erreur 500

## 8. Limites

- ~~`DirectoryIndex`~~ : **résolu** — `dir.conf` global ordonne
  `index.html` avant `index.php` ; `DirectoryIndex index.php` en
  tête du `.htaccess` `/web` (autorisé par `AllowOverride All`).
- Traversée `private/` (701) : confirmée — `www-data` ∈ `client2`.
- Symlink `/build` : directive et propriétaires conformes, mais test
  réel uniquement en ligne — première requête `/build` au go-live =
  test de référence.

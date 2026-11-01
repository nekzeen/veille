# Développement

## Environnement

Le développement se fait **directement sur le serveur** par SSH, dans
`/var/www/clients/client2/web18/private/veille` (= ce dépôt, racine de
l'application Laravel). GitHub sert de remote de sauvegarde/partage.

### Outils disponibles (vérifiés)

| Outil | Version | Emplacement |
|---|---|---|
| PHP CLI | 8.3.36 | `php` (PATH), `/usr/bin/php8.3` |
| Composer | 2.5.5 | `/usr/bin/composer` |
| Git | 2.39.5 | `/usr/bin/git` |
| MariaDB client | 10.11.19 | `mysql` / `mariadb` |
| Node.js | 18.20.4 (système) | `/usr/bin/node` — **insuffisant pour Vite récent** |

Composer 2.5.5 fonctionne mais est ancien ; en cas de problème, télécharger un
`composer.phar` récent dans le projet ou `~/bin` (pas de modification système).

### Node 22 via nvm (build uniquement)

Node n'est utilisé que pour `vite build` / `npm`. À installer lors de M1 :

```bash
curl -o- https://raw.githubusercontent.com/nvm-sh/nvm/v0.40.3/install.sh | bash
source ~/.bashrc
nvm install 22        # installe dans ~/.nvm — aucun droit root requis
nvm alias default 22
```

Node 18 système reste intact ; rien n'est installé en dehors du home.
En cas d'impossibilité de build sur le serveur, le build Vite peut être
produit ailleurs et `public/build/` transféré — décision à prendre en M1.

## Création de l'application (M1)

Le dépôt contient déjà la documentation : `composer create-project` exige un
dossier vide. La version est **épinglée à Laravel 12** (décision validée —
`create-project` sans contrainte installerait la dernière majeure disponible,
aujourd'hui Laravel 13). Procédure :

```bash
cd /var/www/clients/client2/web18/private
composer create-project "laravel/laravel:^12.0" veille-new
rsync -a veille-new/ veille/     # fusionne sans écraser les *.md existants
rm -rf veille-new
cd veille && git add -A          # le .gitignore Laravel est inclus
```

Ensuite installer le starter kit Blade choisi, puis configurer `.env`.

## Configuration `.env` attendue

```ini
APP_NAME=Veille
APP_ENV=production        # local pour un clone de dev
APP_DEBUG=false           # TOUJOURS false sur le serveur
APP_URL=https://veille.gaelmorvan.fr
APP_LOCALE=fr
APP_FALLBACK_LOCALE=fr
APP_TIMEZONE=Europe/Paris

DB_CONNECTION=mariadb
DB_HOST=127.0.0.1
DB_DATABASE=…             # créé via panneau ISPConfig
DB_USERNAME=…
DB_PASSWORD=…

CACHE_STORE=database
QUEUE_CONNECTION=database
SESSION_DRIVER=file
SESSION_SECURE_COOKIE=true

MAIL_MAILER=sendmail
MAIL_FROM_ADDRESS=noreply@veille.gaelmorvan.fr
MAIL_FROM_NAME=Veille
```

`APP_KEY` est généré par `php artisan key:generate` sur le serveur —
jamais commité.

## Commandes quotidiennes

```bash
php artisan test              # suite de tests (sqlite en mémoire)
./vendor/bin/pint             # formatage PSR-12
./vendor/bin/pint --test      # vérification sans écriture
./vendor/bin/phpstan analyse  # analyse statique (Larastan)
composer audit                # vulnérabilités PHP
npm ci && npm run build       # build Vite (sous nvm Node 22)
php artisan migrate           # migrations
php artisan schedule:run      # exécution manuelle du scheduler
php artisan queue:work database --stop-when-empty   # drainer la file
```

## Tests

- **Framework** : Pest (ou PHPUnit natif si préférence — à confirmer en M1).
- **Base de test** : SQLite en mémoire (`phpunit.xml`), indépendante de
  MariaDB — aucune base de test à créer.
- **Couverture minimale attendue** : authentification, policies
  (isolation utilisateur), collecte de flux, matching par mots-clés,
  notifications d'alerte. Ce sont les chemins critiques du projet.
- Les tests HTTP utilisent les traits Laravel (`RefreshDatabase`,
  `actingAs`) ; les appels externes (fetch RSS) sont mockés.

## Workflow Git

- Branche `main` = état déployé/déployable.
- Développement sur branches `feature/…`, `fix/…`, `chore/…`, fusionnées
  par PR sur GitHub (revue humaine ou auto-validée selon le contexte).
- Commits **Conventional Commits** (`feat:`, `fix:`, `docs:`…), sujet en
  anglais, corps libre en français.
- **Jamais de push ni de commit automatisé sans validation explicite** de
  l'utilisateur.
- Avant chaque commit : `pint --test`, `phpstan`, `php artisan test`.
- Identité git à configurer lors de la première contribution
  (`git config user.name/user.email` — non définie actuellement).

## Règles spécifiques au serveur

- **Ne jamais modifier** : le vhost Apache (`/etc/apache2/sites-enabled/`),
  les pools PHP-FPM, aucune configuration système.
- **Ne pas toucher** aux autres sites (`/var/www/clients/*/web*`).
- `/web` ne se modifie que dans le cadre d'une procédure de déploiement
  validée (cf. Deployment.md).
- Pas de dépendance système nouvelle sans validation préalable.
- Le répertoire `tmp/` du site et `storage/` de l'app absorbent les
  écritures temporaires — ne pas écrire ailleurs.

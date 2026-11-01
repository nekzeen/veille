# AGENTS.md — Instructions pour les agents de développement

> À lire **en premier** avant toute mission sur ce dépôt.

## Contexte

Plateforme de veille informationnelle multi-utilisateurs (type Inoreader) :
veilles personnelles = mots-clés + sources RSS/Atom, favoris, notes,
catégories, alertes e-mail. **Pas** de logique pédagogique
(formateur/apprenant/groupes) — ne jamais en introduire.

## Environnement (vérifié le 2026-11-01)

| Élément | Valeur |
|---|---|
| Répertoire projet | `/var/www/clients/client2/web18/private/veille` (racine Laravel = racine du dépôt) |
| Docroot public | `/var/www/clients/client2/web18/web` (ISPConfig, obligatoire) |
| Site | `https://veille.gaelmorvan.fr` |
| PHP | CLI 8.3.36 ; FPM 8.3 via socket `/var/lib/php8.2-fpm/web18.sock` (nom historique) |
| Stack | Laravel 12, Blade + Alpine.js + Tailwind, Vite |
| BDD | MariaDB 10.11 (`DB_*` dans `.env`) — **aucune base n'existe encore** |
| Queue/cache | driver `database` — **pas** de Redis (présent mais partagé/non retenu) |
| Mail | sendmail (`/usr/sbin/sendmail`, Postfix local) |
| Collecte | SimplePie + `php artisan schedule:run` via cron ISPConfig |
| Node | **22 via nvm** (`~/.nvm`) pour le build Vite uniquement — **jamais en production** |
| Logs | `~/log/error.log`, `~/log/access.log` |

## Commandes de référence

```bash
php artisan test                 # tests (sqlite en mémoire)
./vendor/bin/pint --test         # lint
./vendor/bin/phpstan analyse     # analyse statique
composer install --no-dev --optimize-autoloader
source ~/.nvm/nvm.sh && nvm use 22 && npm ci && npm run build
php artisan migrate --force
php artisan config:cache route:cache view:cache
```

Vérifier avant de finir une mission : `pint --test`, `phpstan`,
`php artisan test` — les trois doivent passer.

## Workflow Git

- Un **commit indépendant par étape cohérente et validée** ; ne pas
  accumuler plusieurs fonctionnalités ou missions dans un même commit.
- Avant chaque commit : tests + contrôles qualité, `git diff`,
  `git diff --check`, vérification des fichiers indexés.
- Après chaque commit : communiquer hash, message et résultats des
  contrôles. Jamais de push sans validation explicite de Gaël.
- Détail complet : [Conventions.md](Conventions.md) § Git.

## Interdictions strictes

1. **Ne jamais modifier** le vhost Apache (`/etc/apache2/sites-enabled/`),
   les pools PHP-FPM, ni aucune configuration système/serveur.
2. **Ne jamais toucher** aux autres sites (`/var/www/clients/*` hors web18).
3. **Ne jamais committer** `.env`, secrets, `vendor/`, `node_modules/`,
   `public/build/`.
4. **Push Git** : jamais sans demande ou validation explicite de Gaël.
   **Commits** : un par étape cohérente et validée — voir
   « Workflow Git » ci-dessus et Conventions.md § Git.
5. **Aucune écriture dans `/web`** hors procédure de déploiement validée
   (cf. Deployment.md). Ne pas supprimer `error/`, `stats/`,
   `standard_index.html` — fichiers gérés par ISPConfig.
6. **Aucune migration en base sans validation** ; jamais de
   `migrate:fresh`/`db:wipe` sur la base de production.
7. Pas de nouveau service persistant (daemon, worker, node server) — tout
   passe par le scheduler + queue `database`.
8. Pas d'installation de paquet système ; les outils utilisateur (nvm,
   composer.phar local) se limitent au home `web18`.

## Règles métier à préserver

- Isolation stricte : un utilisateur ne voit que ses veilles, sources
  personnelles, favoris, notes, catégories → Policy obligatoire sur chaque
  modèle utilisateur.
- Mutualisation technique uniquement : `sources`/`items` partagées, état
  utilisateur dans `item_user` / `item_veille`.
- Le partage, s'il arrive, est **facultatif** et explicite.
- Simplicité > richesse fonctionnelle pour le MVP (Roadmap.md).

## Cartographie de la documentation

| Besoin | Document |
|---|---|
| Vision, périmètre, règles métier | [PROJECT.md](PROJECT.md) |
| Architecture, modèle de données, sécurité | [Architecture.md](Architecture.md) |
| Missions et critères de validation | [Roadmap.md](Roadmap.md) |
| Setup, commandes, tests, workflow | [Development.md](Development.md) |
| Déploiement, `/web`, prérequis ISPConfig | [Deployment.md](Deployment.md) |
| Style, Git, tests, docs | [Conventions.md](Conventions.md) |

## En cas de doute

- Les faits "vérifiés" proviennent de l'audit M0 ; ne pas réinventer.
- Les éléments "à vérifier" sont listés dans Deployment.md § Éléments
  restant à vérifier — ne pas les présenter comme acquis.
- Si une tâche demandée contredit une interdiction ci-dessus : s'arrêter
  et demander à l'utilisateur.

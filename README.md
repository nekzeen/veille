# Veille

Plateforme web de **veille informationnelle multi-utilisateurs**, inspirée
fonctionnellement d'Inoreader : chaque utilisateur crée ses propres veilles
(mots-clés + sources RSS/Atom), consulte les articles collectés, gère favoris,
notes et catégories, et reçoit des alertes par e-mail.

- **Domaine** : https://veille.gaelmorvan.fr
- **Dépôt** : https://github.com/nekzeen/veille

## Stack technique (décisions validées)

| Couche | Choix |
|---|---|
| Framework | Laravel 12 — monolithe (pas d'API SPA, pas de Next.js) |
| Frontend | Blade + Alpine.js + Tailwind CSS, compilés par Vite |
| PHP | 8.3 (CLI et FPM) |
| Base de données | MariaDB 10.11 |
| Auth | Sessions Laravel (starter kit Blade) |
| Collecte | SimplePie (RSS/Atom) via Laravel Scheduler + queue `database` |
| Cache / queue | Driver `database` (zéro service additionnel) |
| E-mail | Sendmail (Postfix local) |
| Node.js | 22 via nvm — **outils de build uniquement, jamais en production** |

## État du projet

Mission 0 (audit + documentation) terminée. L'application Laravel n'est pas
encore créée. Voir [Roadmap.md](Roadmap.md) pour la suite.

## Documentation

| Fichier | Contenu |
|---|---|
| [PROJECT.md](PROJECT.md) | Vision, périmètre, règles métier, glossaire |
| [Architecture.md](Architecture.md) | Architecture technique, modèle de données, flux de collecte |
| [Roadmap.md](Roadmap.md) | Découpage en missions et critères de validation |
| [Development.md](Development.md) | Environnement de dev, commandes, tests |
| [Deployment.md](Deployment.md) | Déploiement ISPConfig, exposition dans `/web`, prérequis |
| [Conventions.md](Conventions.md) | Style de code, Git, tests, documentation |
| [AGENTS.md](AGENTS.md) | Instructions pour les agents de développement |

## Emplacements sur le serveur

| Chemin | Rôle |
|---|---|
| `/var/www/clients/client2/web18/private/veille` | **Racine de l'application Laravel** (= ce dépôt) — hors racine publique |
| `/var/www/clients/client2/web18/web` | Racine publique obligatoire (docroot ISPConfig) — n'expose que le front controller et les assets |
| `/var/www/clients/client2/web18/tmp` | Fichiers temporaires (sessions PHP natives, uploads) |
| `/var/www/clients/client2/web18/log` | Logs Apache du site |

Le code applicatif, `.env`, `vendor/` et `storage/` restent **en dehors** du
docroot. Voir [Deployment.md](Deployment.md).

# Roadmap

Découpage en missions incrémentales. Chaque mission produit un livrable
vérifiable. Les critères de validation (Definition of Done) doivent être
satisfaits avant de passer à la suivante.

## M0 — Audit et documentation ✅

**Livrable** : ce jeu de documents dans `/private/veille`.

- [x] Audit d'hébergement validé.
- [x] Décisions d'architecture validées.
- [ ] Création de la base MariaDB + utilisateur (panneau ISPConfig) — **action manuelle requise avant M1**.

## M1 — Socle applicatif

**Objectif** : une application Laravel vierge, déployée et accessible en HTTPS.

- Scaffolding Laravel 12 dans `/private/veille` (procédure dossier non vide,
  cf. Development.md).
- Installation nvm + Node 22 (build Vite uniquement).
- Starter kit Blade (Breeze ou starter officiel équivalent) : inscription,
  connexion, vérification e-mail, mot de passe oublié.
- Tailwind + Alpine.js via Vite.
- `.env` configuré (MariaDB, mail sendmail, queue/cache `database`).
- Cron `schedule:run` créé via le panneau ISPConfig (action manuelle —
  activation nécessaire dès que les tâches planifiées sont utilisées, dès M2).
- Exposition dans `/web` (index.php + .htaccess + symlinks assets,
  cf. Deployment.md).
- Pipeline qualité : Pint, Larastan, suite de tests de base.

**Critères de validation**

- `https://veille.gaelmorvan.fr` répond 200 en HTTPS, HTTP redirige vers HTTPS.
- Inscription → connexion → déconnexion fonctionnelles en production.
- `php artisan test` vert ; `pint --test` et `phpstan` sans erreur.
- Aucun fichier privé (`.env`, `storage/`, `vendor/`) accessible par HTTP.
- Migrations appliquées en base sans erreur.

## M2 — Sources

**Objectif** : les utilisateurs ajoutent et valident des flux RSS/Atom.

- Table `sources` + catalogue (sources `added_by = null`).
- CRUD sources personnelles avec validation du flux (SimplePie, SSRF guard).
- Commande `feeds:fetch` + `FetchFeedJob` (queue `database`).
- GET conditionnel (ETag / Last-Modified), gestion d'erreurs + backoff.
- Stockage `items` avec dédoublonnage.

**Critères de validation**

- Ajouter un flux valide → ses articles apparaissent en base après collecte.
- Ajouter un flux invalide/inexistant → message d'erreur clair, pas de crash.
- Deux utilisateurs abonnés au même flux → un seul téléchargement (items
  mutualisés).
- Flux en échec répété → backoff puis désactivation propre (pas de boucle
  d'erreurs).

## M3 — Veilles

**Objectif** : créer des veilles (nom + mots-clés + sources) et voir les
articles correspondants.

- CRUD veilles + mots-clés + association de sources.
- `KeywordMatcher` → remplissage `item_veille` à l'ingestion et lors de la
  modification d'une veille (rematching).
- Page de lecture d'une veille : liste paginée, triée par date.

**Critères de validation**

- Une veille "mot-clé + source" affiche uniquement les articles pertinents.
- Modification des mots-clés → liste mise à jour.
- Deux veilles différentes sur la même source restent indépendantes.

## M4 — Lecture et organisation

**Objectif** : favoris, notes, catégories, marquage lu/non lu.

- `item_user` : toggle lu/non lu, favori, note (édition inline Alpine.js).
- CRUD catégories ; classement des veilles et/ou favoris.
- Politique de rétention/purge des articles (décision à prendre en M4).

**Critères de validation**

- Marquer lu / favori / noter persiste par utilisateur, indépendamment des
  autres utilisateurs abonnés au même article.
- Les notes ne sont jamais visibles hors de l'espace propriétaire (test de
  Policy).

## M5 — Alertes e-mail

**Objectif** : notifications périodiques par veille.

- `alert_frequency` par veille + `SendAlertJob` + Notification Laravel.
- E-mail HTML/texte listant les nouveaux articles.
- Vérification de la délivrabilité (SPF/DKIM du domaine).

**Critères de validation**

- Une veille en fréquence `daily` génère un e-mail reçu, listant les bons
  articles, une seule fois par période.
- `alert_frequency = none` → aucun envoi.

## Post-MVP (ordre indicatif)

| Évolution | Valeur |
|---|---|
| Import/export OPML | Migration depuis Inoreader/Netvibes |
| Partage d'articles et collections | Collaboration facultative |
| Recherche plein texte (`FULLTEXT` MariaDB) | Retrouvaille dans l'historique |
| Filtres avancés (ET, exclusion, regex) | Veilles de précision |
| API Sanctum (lecture seule d'abord) | Futurs clients (mobile, SPA) |
| Page "tous les non lus" unifiée | Confort Inoreader-like |
| Suggestions de sources / découverte | Croissance du catalogue |
| Vue statistiques de veille | Pilotage |

## Dépendances critiques entre missions

```
M1 (socle) → M2 (sources) → M3 (veilles) → M4 (lecture) → M5 (alertes)
                  └──────────────┴── les items doivent exister pour matcher
```

Le partage et l'API ne peuvent être envisagés qu'après M4.

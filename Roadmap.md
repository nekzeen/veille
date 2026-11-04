# Roadmap

Découpage en missions incrémentales. Chaque mission produit un livrable
vérifiable. Les critères de validation (Definition of Done) doivent être
satisfaits avant de passer à la suivante.

## M0 — Audit et documentation ✅

**Livrable** : ce jeu de documents dans `/private/veille`.

- [x] Audit d'hébergement validé.
- [x] Décisions d'architecture validées.
- [ ] Création de la base MariaDB + utilisateur (panneau ISPConfig) — **action manuelle requise avant M1**.

## M1 — Socle applicatif ✅ (code) / ⬜ (mise en production)

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

- `https://veille.gaelmorvan.fr` répond 200 en HTTPS, HTTP redirige vers HTTPS. ⬜ non vérifié — `/web` non exposé
- Inscription → connexion → déconnexion fonctionnelles en production. ⬜ non vérifié
- `php artisan test` vert ; `pint --test` et `phpstan` sans erreur. ✅
- Aucun fichier privé (`.env`, `storage/`, `vendor/`) accessible par HTTP. ⬜ non vérifié
- Migrations appliquées en base sans erreur. ⬜ `veille_db` en attente d'autorisation

## M2 — Sources ✅ (M1.9 + M1.10)

**Objectif** : les utilisateurs ajoutent et valident des flux RSS/Atom.

- Table `sources` + catalogue (sources `added_by = null`).
- CRUD sources personnelles avec validation du flux (SimplePie, SSRF guard).
- Commande `feeds:fetch` + `FetchFeedJob` (queue `database`).
- GET conditionnel (ETag / Last-Modified), gestion d'erreurs + backoff.
- Stockage `articles` avec dédoublonnage (`dedup_key`).

**Critères de validation**

- Ajouter un flux valide → ses articles apparaissent en base après collecte.
- Ajouter un flux invalide/inexistant → message d'erreur clair, pas de crash.
- Deux utilisateurs abonnés au même flux → un seul téléchargement (items
  mutualisés).
- Flux en échec répété → backoff puis désactivation propre (pas de boucle
  d'erreurs).

## M3 — Veilles ✅ (M1.11 + M1.12)

**Objectif** : créer des veilles (nom + mots-clés + sources) et voir les
articles correspondants.

- CRUD veilles + mots-clés + association de sources.
- `KeywordMatcher` → remplissage `article_veille` à l'ingestion et lors
  de la modification d'une veille (`VeilleRematcher`).
- Page de lecture d'une veille : liste paginée, triée par date,
  lecture interne assainie (HTMLPurifier), prev/next, CSP.

**Critères de validation**

- Une veille "mot-clé + source" affiche uniquement les articles pertinents.
- Modification des mots-clés → liste mise à jour.
- Deux veilles différentes sur la même source restent indépendantes.

## M4 — Lecture et organisation ✅ (M1.13 + M1.14)

**Objectif** : favoris, notes, catégories, marquage lu/non lu.

- `article_user` : toggle lu/non lu, favori, note, archivage.
- Espaces personnels : « Mes favoris », « Mes archives »,
  « Mes catégories ».
- CRUD catégories ; classement/retrait d'articles depuis les fiches
  veille et favoris ; liste d'articles par catégorie.
- Politique de rétention/purge des articles : six mois (§4.E du
  DataModel) ; favori, note, catégorie ou accès personnel protègent.

**Critères de validation**

- Marquer lu / favori / noter persiste par utilisateur, indépendamment
  des autres utilisateurs abonnés au même article.
- Les notes ne sont jamais visibles hors de l'espace propriétaire
  (tests de Policy/isolation).
- Articles archivés masqués des listes ordinaires, accessibles via
  « Mes archives ».

## M5 — Alertes e-mail ✅ (M5.1–M5.6)

**Objectif** : digest quotidien par veille.

- `alerts_enabled` par veille ; génération `alerts:generate` à 06:30
  Europe/Paris (curseur fiable `article_veille.id` sous verrou
  `veilles`, ≤50 articles, jamais de digest vide).
- Outbox `veille_alerts` : réservation atomique (bail 10 min, token,
  3 tentatives), distribution `alerts:distribute`, reprise
  `alerts:sweep`, envoi `SendVeilleDigestJob` + `VeilleDigestMail`
  (HTML/texte).
- Vérification de la délivrabilité (SPF/DKIM du domaine) — **action
  manuelle restante côté Postfix/domaine**.

**Critères de validation**

- Une veille avec `alerts_enabled` génère un e-mail listant les
  nouveaux articles, une fois par jour civil Europe/Paris.
- `alerts_enabled` désactivé → aucun envoi.
- Livraison au moins une fois (doublon résiduel accepté en cas de
  crash entre envoi et clôture).

## M8 — Collecte multicanale (conception validée M8.1)

| Étape | Périmètre | Criticité |
|---|---|---|
| M8.2 | Connecteurs + normalisation + compat. RSS | indispensable |
| M8.3 | `SourceDetector` + prévisualisation backend | indispensable |
| M8.4 | Extraction HTML + formats structurés | indispensable |
| M8.5 | Interface ajout / aperçu / correction | indispensable |
| M8.6 | Sécurité, robustesse, tests | indispensable |
| M8.7 | `ApiConnector` | différable |

Décisions : `submitted_url`/`fetch_url`, `config` JSON versionnée,
`config_state` = configuration seule (`detected`/`needs_review`/
`validated`) — erreurs de collecte dans `fail_count`/`last_error`,
anti-bot = échec opérationnel (pas de contournement), aucun JS exécuté.

## Post-MVP (ordre indicatif)

| Évolution | Valeur |
|---|---|
| ~~Tableau de bord~~ | ✅ Livré (M7.14/M7.15) — indicateurs, veilles, derniers articles, état collectes |
| Import/export OPML | Migration depuis Inoreader/Netvibes |
| Partage d'articles et collections | Collaboration facultative |
| Recherche plein texte (`FULLTEXT` MariaDB) | Retrouvaille dans l'historique |
| Filtres avancés (ET, exclusion, regex) | Veilles de précision |
| API Sanctum (lecture seule d'abord) | Futurs clients (mobile, SPA) |
| Page "tous les non lus" unifiée | Confort Inoreader-like |
| Suggestions de sources / découverte | Croissance du catalogue |
| Vue statistiques de veille | Pilotage |

## Prochaine étape — préparation au déploiement (proposée)

Toutes les fonctionnalités du MVP sont implémentées et publiées
(jusqu'à `4f3bc92`). La suite n'est pas un nouveau jalon officiel
mais la mise en production, qui reste soumise aux validations de
Gaël :

1. Audit de préparation au déploiement (`.env` prod, caches, build
   Vite).
2. Vérification de l'environnement de production (PHP-FPM,
   extensions, permissions, cron, mail sendmail/SPF-DKIM).
3. Exposition `/web` selon Deployment.md (aucune écriture hors
   procédure validée).
4. **Migrations métier sur `veille_db` : autorisation explicite de
   Gaël requise** (12 migrations en attente, sauvegarde préalable).
5. Recette fonctionnelle navigateur (inscription → source →
   collecte → veille → lecture → digest).

## Dépendances critiques entre missions

```
M1 (socle) → M2 (sources) → M3 (veilles) → M4 (lecture) → M5 (alertes)
                  └──────────────┴── les items doivent exister pour matcher
```

Le partage et l'API ne peuvent être envisagés qu'après M4.
- M9.1–M9.5 : exclusions personnelles (CRUD + matcher + pipeline + rétroactif + docs) livrées — voir M9_Conception_Exclusions.md et DataModel §13.

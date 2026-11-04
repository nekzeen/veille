# Plan de déploiement M8 (préparé en M8.7.1, non exécuté)

> Périmètre : publier M8.1 bis → M8.6. Ce document ne lance rien.
> Chaque étape exige une validation explicite de Gaël avant exécution.

## 0. Point de départ (vérifié)

- Travail non commité : **50 fichiers** (`M` 37 + `??` 13) — code M8.2→M8.6,
  docs M8.1 bis, theming M8.5, tests, fixtures. Aucun secret repéré,
  aucun fichier temporaire à exclure (public/build régénéré, ignoré par
  Git — rebuild local requis).
- Migration unique en attente : `2026_11_04_170909_add_connector_columns_to_sources_table`
  — statut `Pending`. Son horodatage est **antérieur** aux migrations
  2026_11_05 déjà `Ran` : `migrate` la traitera quand même (les pending
  sont toujours appliqués), aucune migration existante n'a été modifiée.
- Garde active : `Schema::hasColumn('sources','connector')` dans
  `SourceSubscriptionService` (chemins `addPersonalSource` et
  `addDetectedSource`) + refus des connecteurs non-RSS tant que les
  colonnes manquent.
- Routes M8.3–M8.5 absentes du cache `bootstrap/cache/routes-v7.php`
  (prod figée ; tests via `APP_ROUTES_CACHE`).

## 1. Sauvegarde (avant TOUT code/BD)

```bash
cd /var/www/clients/client2/web18/private/veille
TS=$(date +%Y%m%d-%H%M%S)
B=/var/www/clients/client2/web18/private/backups/veille/$TS
mkdir -p "$B"
# 1a. Dump base (auth via .env — jamais en clair ici)
php artisan db:show --json >/dev/null 2>&1 || true
mysqldump -u veille_usr -p --single-transaction --quick veille_db \
  | gzip > "$B/veille_db.sql.gz"
# 1b. Vérification intégrité
gunzip -t "$B/veille_db.sql.gz" && zcat "$B/veille_db.sql.gz" | tail -3
sha256sum "$B/veille_db.sql.gz" > "$B/veille_db.sql.gz.sha256"
# 1c. Sauvegarde applicative ciblée (fichiers modifiés M8)
git stash list >/dev/null  # état actuel conservé implicitement par git diff
git diff > "$B/m8-working-tree.patch"
git status --porcelain > "$B/git-status.txt"
cp bootstrap/cache/routes-v7.php "$B/routes-v7.php.bak"
cp bootstrap/cache/config.php "$B/config.php.bak" 2>/dev/null || true
```

Critère de poursuite : `gunzip -t` OK **et** taille du dump cohérente
(> 100 Kio attendus) **et** `git status` capturé. Sinon STOP.

## 2. Séquence de déploiement (ordre obligatoire)

| # | Opération | Vérification immédiate |
|---|---|---|
| 1 | Sauvegarde §1 complète | dump vérifiable + sha256 |
| 2 | `git add -A && git commit` (commits M8 par étapes validées, jamais de force) | `git status` propre, `git diff --check` |
| 3 | `php artisan down --retry=30` | page maintenance visible |
| 4 | `php artisan migrate --force` (1 seule pending) | `migrate:status` → `Ran` ; `DESCRIBE sources` → 6 colonnes ; `SELECT COUNT(*) FROM sources WHERE connector='rss' AND config_state='validated'` → **7** |
| 5 | `php artisan route:cache` (publie detect/preview/confirm) | `route:list` contient `sources.detect` |
| 6 | `php artisan config:cache view:cache` | site 200, login OK |
| 7 | `source ~/.nvm/nvm.sh && nvm use 22 && npm ci && npm run build` (public/build est ignoré par Git — rebuild obligatoire, déjà fait en local) | manifest à jour |
| 8 | `php artisan up` + scheduler (`schedule:run` manuel une fois) | `storage/logs/schedule.log`, aucun `last_error` nouveau sur les 7 flux |

### Vérifications fonctionnelles (post-deploy)

- Les 7 sources RSS collectent (log `feeds.fetch.succeeded`).
- Ajout manuel RSS via formulaire historique → `connector='rss'`, `config_state='validated'` (garde inoffensive mais présente).
- `POST /sources/preview` sur un flux → JSON `detected` ; sur une page HTML → `needs_review` + items.
- `POST /sources/confirm` HTML/JSON Feed → source `validated` créée.

## 3. Retrait de la garde `Schema::hasColumn`

**Moment** : après l'étape 8 validée (colonnes présentes, 7 sources OK,
collecte OK). Retirer les deux blocs `if (Schema::hasColumn(...))` de
`addPersonalSource` et `addDetectedSource` + le `use Schema;` inutilisé
— commit dédié `chore: drop pre-migration column guards` — puis
`php artisan test` pour non-régression avant de le garder en ligne.

## 4. Retour arrière

| Échec | Rollback |
|---|---|
| Code/UI | `git revert`/checkout du commit fautif + `view:cache` — zéro perte |
| Migration seule (avant commit de configs M8.5) | `php artisan migrate:rollback --step=1` → **supprime les 6 colonnes et leurs contenus** (config, detection) — données RSS historiques conservées mais rollback **avec perte** des nouvelles configurations |
| État corrompu | restore `routes-v7.php.bak`/`config.php.bak` + `gunzip -c veille_db.sql.gz \| mysql veille_db` (procédure complète : `Backup_Restore.md` — validée réellement en M7.2) |

## 5. Risques résiduels

- `migrate` sur schéma live : additive, ~instantanée (7 lignes), table
  `sources` petite — risque faible ; fenêtre maintenance prévue quand même.
- `route:cache` fige les nouvelles routes : réversible en restaurant
  `routes-v7.php.bak`.
- Enrichissement HTML (`enrich_pages`) peut allonger un job : borne 3,
  désactivé par défaut.

**Recommandation : prêt sous conditions** — exécution uniquement sur
validation explicite, après sauvegarde §1 vérifiée, hors heure de pointe.

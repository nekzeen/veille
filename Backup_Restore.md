# Backup & Restore — Veille

> Procédure de référence validée (M6.5/M6.5 bis/M6.5 ter).
> **Aucune sauvegarde automatique n'existe** : chaque exécution est
> manuelle et décidée avant déploiement ou migration.

## 1. Inventaire

| Élément | Taille | Indispensable | Reproductible |
|---|---|---|---|
| `veille_db` (MariaDB 10.11, InnoDB) | ~0.3 MB avant prod | ✅ | — |
| Code `private/veille` (hors exclusions) | ~10 MB | ✅ | `vendor/`, `node_modules/`, `public/build`, caches |
| `.env` | ~1.3 KB | ✅ **(secrets — à part)** | — |
| `storage/` (logs, app, htmlpurifier, uploads) | variable | ✅ | `framework/cache` seulement |
| `/web` (docroot ISPConfig) | 700 KB | ✅ | — |
| Référence Git | `git-sha.txt` | ✅ | remote `origin` |

## 2. Emplacement et droits

```bash
BK=/var/www/clients/client2/web18/private/backups/veille/$(date +%Y%m%d-%H%M)
mkdir -p "$BK"
chmod 700 /var/www/clients/client2/web18/private/backups
```

- **Jamais dans `/web`** — hors docroot, propriété `web18` (mode 700).
- `.env` sauvegardé séparément, `env.bak` en `600`.
- Conservation : avant chaque déploiement/migration + 30 jours.

## 3. Commandes de sauvegarde (validées M6.5 ter)

```bash
# 1. Base — snapshot InnoDB cohérent (toutes tables InnoDB vérifiées)
mariadb-dump --single-transaction --triggers --tz-utc \
  --default-character-set=utf8mb4 veille_db | gzip > "$BK/veille_db.sql.gz"

# 2. Applicatif — exclusions vérifiées (451 membres, 0 interdit)
tar czf "$BK/app.tar.gz" \
  --exclude='veille/.env' \
  --exclude='veille/.env.*' \
  --exclude='veille/.git' \
  --exclude='veille/vendor' \
  --exclude='veille/node_modules' \
  --exclude='veille/public/build' \
  --exclude='veille/bootstrap/cache' \
  --exclude='veille/storage/framework/cache' \
  -C /var/www/clients/client2/web18/private veille

# 3. .env — sauvegarde à part, droits 600
cp -p /var/www/clients/client2/web18/private/veille/.env "$BK/env.bak"
chmod 600 "$BK/env.bak"

# 4. Docroot ISPConfig
tar czf "$BK/web.tar.gz" -C /var/www/clients/client2/web18 web

# 5. Référence exacte du code
cd /var/www/clients/client2/web18/private/veille && git rev-parse HEAD > "$BK/git-sha.txt"
```

## 4. Contrôles d'intégrité

```bash
gzip -t "$BK/veille_db.sql.gz"                    # archive valide
zcat "$BK/veille_db.sql.gz" | tail -1             # "Dump completed" présent
tar tzf "$BK/app.tar.gz" > /dev/null              # archive lisible
tar tzf "$BK/app.tar.gz" | grep -E '(^|/)\.env($|\.)'
# → ne doit rien retourner hormis ".env.example" — CONFIRMER VIDE
tar tzf "$BK/web.tar.gz" | head -20               # inventaire docroot
```

**Validation du dump en base temporaire** (jamais `veille_db`) :

```bash
mariadb -e "CREATE DATABASE veille_restore_check"
gunzip -c "$BK/veille_db.sql.gz" | mariadb veille_restore_check
mariadb -e "SHOW TABLES" veille_restore_check       # comparaison manuelle
mariadb -e "DROP DATABASE veille_restore_check"     # base jetable
```

Nécessite `CREATE/DROP DATABASE` pour `veille_usr` — **non confirmé**,
à vérifier lors du premier dump réel (ou création via ISPConfig).

## 5. Restauration SQL

### 5.1 Partielle (tables connues du dump)

```bash
# PRÉREQUIS : sauvegarde de l'état courant + accord explicite de Gaël
mariadb-dump --single-transaction veille_db | gzip > "$BK/pre-restore.sql.gz"
gunzip -c "$BK/veille_db.sql.gz" | mariadb veille_db
```

`mariadb-dump` émet `DROP TABLE IF EXISTS` avant chaque `CREATE` —
les tables du dump sont recréées à l'état sauvegardé.

### 5.2 Intégrale (inclut les tables apparues après le dump)

Le dump **ignore** les tables créées après la sauvegarde — elles
subsisteraient orphelines et incohérentes. La restauration intégrale
exige :

```bash
# ⚠ EXÉCUTION MANUELLE EXCLUSIVE — jamais automatisée.
# Commande à saisir par Gaël après confirmation écrite :
mariadb -e "DROP DATABASE veille_db; CREATE DATABASE veille_db CHARACTER SET utf8mb4"
gunzip -c "$BK/veille_db.sql.gz" | mariadb veille_db
```

**Avertissement** : toute donnée créée après la sauvegarde
(utilisateurs, veilles, articles, états) est **définitivement perdue**.

## 6. Restauration des fichiers

### 6.1 Écrasement (par-dessus — fichiers post-dump conservés)

```bash
tar xzf "$BK/app.tar.gz" -C /var/www/clients/client2/web18/private/
# limite : un fichier apparu après le dump n'est pas supprimé → orphelin.
```

### 6.2 Isolée (un chemin)

```bash
tar xzf "$BK/app.tar.gz" -C /tmp/veille-one veille/app/Services/... # comparaison
```

### 6.3 Intégrale — extraction temporaire + bascule contrôlée

**Le répertoire temporaire doit être sur le même système de fichiers
que la cible** (`/var/www/clients/client2/web18/` — pas `/tmp` si
monté autre part) :

```bash
# 1. Extraction en zone temporaire — même FS que la cible
mkdir /var/www/clients/client2/web18/private/veille-restore
tar xzf "$BK/app.tar.gz" -C /var/www/clients/client2/web18/private/veille-restore
# → veille-restore/veille/ (sans .env, .git, vendor, caches)

# 2. Réinsertion des secrets
cp -p "$BK/env.bak" /var/www/clients/client2/web18/private/veille-restore/veille/.env
chmod 600 /var/www/clients/client2/web18/private/veille-restore/veille/.env

# 3. Bascule — l'ancien n'est JAMAIS supprimé automatiquement
mv private/veille private/veille.prev && \
mv private/veille-restore/veille private/veille

# 4. Reconstruction des reproductibles
cd private/veille
composer install --no-dev -o
source ~/.nvm/nvm.sh && nvm use 22 && npm ci && npm run build
php artisan storage:link && php artisan config:cache route:cache view:cache
```

**En cas d'échec à une étape** : l'ancien `private/veille.prev`
reste intact — retour immédiat `mv veille.prev veille`. Ne supprimer
`veille.prev` qu'après validation fonctionnelle complète (décision
de Gaël). **Aucune suppression récursive approximative** (`rm -rf`
sur la cible) n'est une méthode normale de restauration.

### 6.4 `/web` — docroot ISPConfig

`/web` contient les fichiers ISPConfig (`error/`, `stats/`,
`standard_index.html`, `favicon.ico`, `robots.txt`) qui **ne doivent
pas être remplacés sans vérification**. Restauration uniquement
fichier par fichier après comparaison avec `web.tar.gz` :

```bash
tar tzf "$BK/web.tar.gz"                            # inventaire
# extraction sélective des seuls fichiers applicatifs manquants —
# JAMAIS de restauration aveugle du docroot entier.
```

## 7. Propriétaires et permissions

- `tar` préserve les propriétaires **uniquement** en extraction par
  `root` — par `web18`, les fichiers sont `web18:client2` (correct).
- Après bascule : `ls -l` de contrôle sur `storage/`, `bootstrap/cache`
  et `.env` (600 attendu). Ne pas présumer : vérifier réellement.

## 8. Retour arrière par couche

| Couche | Méthode | Limite |
|---|---|---|
| Code | `git checkout <sha>` + rebuild | aucune perte |
| `/web` | remise des fichiers ISPConfig (`web.tar.gz`) | aucune perte |
| `.env` | `cp env.bak .env` | aucune perte |
| Base | restauration §5 | **perte des données post-dump** — sauvegarde obligatoire avant |

## 9. Checklist

**Avant déploiement** : dump `veille_db` ✓ archive app (`.env` absent
vérifié) ✓ `env.bak` 600 ✓ `web.tar.gz` ✓ `git-sha.txt` ✓ intégrité
§4 ✓ sauvegarde hors `/web` ✓.

**Après déploiement** : recette fonctionnelle ✓ logs sans erreur ✓
`schedule.log` alimenté ✓ `veille.prev` conservé jusqu'à validation ✓
dump de l'état post-migration conseillé ✓.

## 10. Limites connues

- Privilèges `mariadb-dump`/`CREATE DATABASE` de `veille_usr` **non
  confirmés** — premier dump réel = test de référence.
- `--single-transaction` ne protège pas contre les DDL concurrents.
- `veille_db` devient non reproductible dès le premier utilisateur
  créé — le dump pré-migration est le seul point de retour garanti.

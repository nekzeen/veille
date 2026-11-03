# Production Migrations — Veille

> Procédure préparée (M6.8) — **aucune migration lancée**. L'exécution
> sur `veille_db` exige l'autorisation explicite de Gaël et une
> sauvegarde préalable (Backup_Restore.md).

## 1. État réel vérifié (lecture seule)

| Élément | Constaté |
|---|---|
| `veille_db` | 9 tables InnoDB framework : `users`(≈1 ligne), `sessions`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`, `migrations`(3), `password_reset_tokens` |
| Ran | 3 framework (batch 1) — `create_users`, `create_cache`, `create_jobs` |
| Pending | **12 métier** (10 × `2026_11_01` + 2 × `2026_11_05`) |
| Données | quasi vides — aucune table métier existante, aucun risque de collision ni backfill |

## 2. Analyse des 12 migrations

### Créations (10 — `CREATE TABLE` pur, `down()` = `dropIfExists`)

| # | Table | Clés/contraintes notables | Risque |
|---|---|---|---|
| 01 | `categories` | `UNIQUE(user_id,name)`, `UNIQUE(user_id,id)` — parent FK composite | nul |
| 02 | `sources` | `UNIQUE(feed_url)`, indexes activité/catalogue/orphelin | nul |
| 03 | `user_sources` | `UNIQUE(user_id,source_id)`, FK CASCADE ×2 | nul |
| 04 | `veilles` | `UNIQUE(user_id,name)`, **`UNIQUE(id,user_id)`** — parent obligatoire des FK composites | nul |
| 05 | `veille_keywords` | FK composite →`veilles(id,user_id)` CASCADE ; `UNIQUE(veille_id,term)` | nul |
| 06 | `source_veille` | FK composite + FK `source_id` CASCADE | nul |
| 07 | `articles` | FK `source_id` **RESTRICT** ; `UNIQUE(source_id,dedup_key)` ; `longText` | nul |
| 08 | `article_veille` | FK composite + FK `article_id` CASCADE ; `created_at(6)` ; PK composite `(veille_id,article_id)` | nul |
| 09 | `article_user` | FK composite `(user_id,category_id)`→`categories` **RESTRICT** | nul |
| 10 | `veille_alerts` | `UNIQUE(veille_id,digest_date)` ; 3 indexes réservation ; FK composite CASCADE | nul |

### Altérations (2 — M5, `ALTER` sur tables vides)

| # | Opération | Risque |
|---|---|---|
| 11 | `article_veille` `+ id BIGINT UNSIGNED AUTO_INCREMENT UNIQUE FIRST` | nul — table vide, instantané, `down()` propre |
| 12 | `veille_alerts` `+ start_match_id`, `+ end_match_id` nullable | nul — `down()` propre |

**Compatibilité MariaDB 10.11** : FK composites requièrent un index
parent unique sur les colonnes exactes — `veilles(id,user_id)` et
`categories(user_id,id)` présents ✓ ; `AUTO_INCREMENT UNIQUE` hors PK
autorisé ✓ ; `timestamp(6)` supporté ✓. Ordre des dépendances :
categories(01)→article_user(09), sources(02)→articles(07),
veilles(04)→enfants(05,06,08,10) — cohérent avec l'ordre fichiers.

## 3. Séquence d'exécution

```bash
# PRÉPARATOIRES (sans autorisation supplémentaire)
cd /var/www/clients/client2/web18/private/veille
git status -sb                        # référence attendue
php artisan migrate:status            # 12 Pending confirmés
# sauvegarde complète (Backup_Restore.md §3) + intégrité du dump
# config prod vérifiée (Production_Configuration.md)

# AUTORISATION GAËL REQUISE À PARTIR D'ICI
php artisan migrate --force           # batch unique des 12
php artisan migrate:status            # 15 Ran attendus
# contrôles post-exécution ci-dessous
```

**Contrôles post-exécution** :

```bash
php artisan tinker --execute='echo count(\DB::select("SHOW TABLES"));'
# → 22 tables attendues (9 framework + 10 métier + jobs déjà comptées → 21+)
php artisan tinker --execute='foreach(\DB::select("SELECT TABLE_NAME t FROM information_schema.table_constraints WHERE table_schema=DATABASE() AND CONSTRAINT_TYPE=\"FOREIGN KEY\"") as $r) echo $r->t."\n";'
# → FK présentes : user_sources, source_veille, articles, article_veille,
#   article_user, veille_keywords, veille_alerts
php artisan test                        # SQLite — non régression
php artisan test -c phpunit.mariadb.xml # veille_test — non régression
curl -Is https://veille.gaelmorvan.fr   # recette (si /web exposé)
```

## 4. Gestion des incidents

| Incident | Conduite |
|---|---|
| Migration échouée | Lire l'erreur ; `migrate:status` — la migration n'est pas Ran ; corriger la cause ; relancer `migrate --force` |
| Partiellement appliquée | `SHOW TABLES` + `information_schema` pour l'état réel ; ne pas deviner ; réparer à la main ou restaurer le dump |
| FK invalide | MariaDB rejette — vérifier index parent (`SHOW INDEX FROM veilles`) ; la migration n'a pas commité le schéma partiellement |
| Erreur privilège | `SHOW GRANTS` — `ALTER/CREATE` manquant → action ISPConfig par Gaël |
| Lock/timeout | `SHOW PROCESSLIST` — tables vides = instantané, ne devrait pas arriver ; si oui : patienter ou `KILL` par Gaël |
| Schéma différent | **s'arrêter** — ne pas forcer ; comparer `SHOW CREATE TABLE` vs migration |

**Jamais de `migrate:rollback` automatique en production** — retour
via dump Backup_Restore.md si nécessaire (base vide de toute façon
avant le premier utilisateur métier).

## 5. Autorisations requises

1. **Exécution `migrate --force` sur `veille_db`** — décision de Gaël.
2. Sauvegarde réelle préalable (dump + archives).
3. Correction d'éventuels privilèges manquants (ISPConfig).
4. Toute intervention corrective sur le schéma après échec.

## 6. Limites

- `migrate:status` en lecture seule vérifié ; privilèges DDL non
  prouvés en exécution.
- `users` contient ≈1 ligne — identité non examinée (confidentialité) ;
  les migrations métier n'y touchent pas.

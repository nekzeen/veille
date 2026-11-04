# M9 — Conception : exclusions personnelles (proposition, non implémentée)

## 1. Principe

Règles d'exclusion **par utilisateur**, **communes à toutes ses veilles** :
un utilisateur écarte des articles chez lui seul. Les `articles` restent
partagés ; rien n'est supprimé du référentiel ni chez les autres.

## 2. Modèle de données (2 tables, `user_id` partout — isolation par composite FK comme l'existant)

```sql
user_exclusions (
  id BIGINT PK,
  user_id BIGINT FK users CASCADE,
  kind ENUM('url','keyword'),
  pattern VARCHAR(500) NOT NULL,          -- domaine, *.domaine, URL, /chemin/ ou expression
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at, updated_at,
  UNIQUE(user_id, kind, pattern(191))     -- pas de doublon par utilisateur
)
```

Une table unique suffit (pattern libre typé par `kind`) ; `is_active` permet
désactivation sans perte. Pas de colonne sur `articles` (commun).

### Écartement rétroactif — `article_user_hidden`

```sql
article_user_hidden (
  user_id BIGINT,
  article_id BIGINT,
  exclusion_id BIGINT NULL,               -- traçabilité, NULL si balayage manuel
  created_at TIMESTAMP,
  PK(user_id, article_id)
)
```

Lecture : `WHERE NOT EXISTS` — index naturel, coût O(log n), pas de balayage.
Restauration : suppression des lignes quand la règle est désactivée/supprimée
(`DELETE WHERE exclusion_id = ?`) → réversible, articles non détruits.

## 3. Règles de correspondance

### URL — comparaison sur l'URL **résolue** (`ArticleLinkResolver::resolve`), pas le domaine du flux
- `indeed.com` : hôte == indeed.com (pas de sous-domaines)
- `*.indeed.com` : hôte se termine par `.indeed.com`
- URL exacte : égalité après `ArticleUrlNormalizer::normalize` (canonique)
- `/freelance/` : préfixe du `path` de l'URL résolue

### Mots-clés — réutiliser `KeywordMatcher::normalize` (casse + NFD + accents)
Contient-match sur `title + ' ' + summary + ' ' + content` normalisé ;
une correspondance suffit. Indépendant des mots-clés de veille.

## 4. Emplacement exact du filtrage

**Collecte** : dans `ArticleVeilleWriter` (point unique où article ↔ utilisateur
est décidé), AVANT l'insert `article_veille` : si l'article matche une règle
active de `article_veille.user_id` → `article_user_hidden` + skip insert.
Avantage : écriture déjà verrouillée (FOR UPDATE existant), aucun doublon
de logique, les autres utilisateurs inchangés.

**Affichage** : `NOT EXISTS (SELECT 1 FROM article_user_hidden WHERE user_id=? AND article_id=articles.id)`
ajouté aux requêtes `VeilleArticleController`, `FavoriteController`,
`ArchiveController`, `DashboardController` (derniers articles), catégories,
`KeywordMatcher` n'a pas besoin d'être filtré (l'insert article_veille est déjà bloquée à l'écriture).

**Rétroactif** : à la création d'une règle → job `ApplyExclusionJob(user_id, exclusion_id)`
qui marque les `article_veille` déjà associés → insert `article_user_hidden` +
delete `article_veille` de l'utilisateur (via Writer). Suppression règle → purge
`article_user_hidden` (les articles ne réapparaissent dans les veilles que si
encore dans le referentiel et re-matchés — documenter cette limite).

## 5. Interface

`/settings/exclusions` (« Mes exclusions », lien dans le menu profil) — 2 cartes :
Domaines/URL (table + sélecteur de type + input) et Expressions (chips/textarea) ;
actions ajouter/modifier/activer (toggle)/supprimer, badge « n articles écartés ».
Style identique (cartes blanches, violet, chip).

## 6. Performances

Collecte : 1 requête règles par user + match en mémoire (règles < 100 typiquement,
regex compilées en cache requête). Affichage : NOT EXISTS indexé. Rétroactif :
job batch par 1000, idempotent.

## 7. Missions proposées

- **M9.1** : table `user_exclusions` + CRUD `ExclusionController` + policies + UI (2 sections).
- **M9.2** : `ExclusionMatcher` service (URL→4 types, keywords→normalize partagé) + tests.
- **M9.3** : intégration `ArticleVeilleWriter` + `article_user_hidden` + filtre lectures + tests d'isolation.
- **M9.4** : `ApplyExclusionJob` rétroactif + badge compteurs + toggle.
- **M9.5** : audit perf + docs (DataModel §13, AGENTS, Roadmap).

## 8. Cas limites à tester

`*.indeed.com` vs `indeed.com` ; URL google résolue puis exclue ; expression à
accents (« contenu sponsorisé » vs résumé sans accents) ; règle d'user A sans
effet sur user B ; désactivation → réapparition ; article commun conservé ;
unsubscribe source avec règles actives ; veille inactive ; double exclusion
(url+keyword) ; pattern invalide refusé ; dédup cross-veille intacte.

**Décision attendue** : validation de l'approche `article_user_hidden` +
filtrage à l'écriture (recommandé : réversible, isolé, performant) avant M9.1.

## 9. Multi-correspondances, réconciliation et concurrence (M9.0 bis)

### 9.1 Une ligne = une justification

`article_user_hidden` devient une table de jonction à 3 colonnes :

```sql
article_user_hidden (
  user_id BIGINT,
  article_id BIGINT,
  exclusion_id BIGINT NULL,            -- NULL = masquage manuel futur
  created_at TIMESTAMP,
  PRIMARY KEY (user_id, article_id, exclusion_id),
  INDEX idx_exclusion (exclusion_id),  -- réconciliation par règle
  FOREIGN KEY (user_id) REFERENCES users ON DELETE CASCADE,
  FOREIGN KEY (exclusion_id) REFERENCES user_exclusions ON DELETE CASCADE
)
```

Chaque règle qui justifie le masquage d'un article produit **sa propre ligne**.
Un article est masqué tant qu'au moins une ligne le relie à une règle **active**
(ou à un masquage manuel) :

```sql
NOT EXISTS (
  SELECT 1 FROM article_user_hidden h
  WHERE h.user_id = ? AND h.article_id = articles.id
    AND (h.exclusion_id IS NULL
         OR EXISTS (SELECT 1 FROM user_exclusions e
                    WHERE e.id = h.exclusion_id AND e.is_active = 1))
)
```

La lecture ne joint que la petite table des règles — indexée, pas de balayage.

### 9.2 Cycle de vie d'une règle

| Action | Effet sur les lignes | Visibilité |
|---|---|---|
| Création | job rétroactif : insert des lignes justifiées (`INSERT IGNORE`, PK 3 colonnes) | masquage immédiat à l'insert |
| **Modification du pattern** | transaction : `DELETE h WHERE exclusion_id=?` puis réinsertion des nouvelles correspondances pour **cette règle seule** | les justifications des autres règles subsistent → pas de réapparition prématurée |
| **Désactivation** (`is_active=0`) | aucune ligne touchée | jointure sur `e.is_active` → masquage suspendu ; réactivation = réapparition des lignes, sans recalcul |
| **Suppression** | `ON DELETE CASCADE` supprime les lignes de la règle | les articles justifiés par d'autres règles actives restent masqués |

Conséquence : un article ne réapparaît que lorsqu'il ne reste **aucune**
justification active — jamais par effet de bord d'une règle voisine.

### 9.3 Même moteur pour collecte et rétroactif

`ExclusionMatcher` est l'unique point de vérité :

```php
// API unique — utilisée par ArticleVeilleWriter ET ApplyExclusionJob
ExclusionMatcher::justifications(
    userId: int,
    url: ?string,        // URL résolue (ArticleLinkResolver) ou articles.url
    haystack: string,    // title + ' ' + summary + ' ' + content, normalisé
): list<int>             // exclusion_id des règles correspondantes
```

- Normalisation texte : `KeywordMatcher::normalize` (même code, même ordre).
- Normalisation URL : `ArticleUrlNormalizer::normalize` pour l'égalité exacte
  et `ArticleLinkResolver::resolve` pour le domaine de destination —
  **jamais** le domaine du flux RSS.
- Collecte : les champs viennent de `FeedItemData` (déjà résolus par les
  connecteurs). Rétroactif : `articles.url` est déjà la forme résolue
  (stockée post-M9.2 en amont) — même champs, mêmes règles, zéro divergence.
- Si un article stocké porte encore une URL non résolue (historique), le
  rétroactif ré-applique `ArticleLinkResolver::resolve` à la volée — même
  code, même résultat.

### 9.4 Transactions et doublons

- `ApplyExclusionJob` traite par lots de 500 articles, **une transaction par
  lot**, idempotent : `INSERT IGNORE` (PK 3 colonnes → aucun doublon même en
  cas de réessai ou de deux jobs concurrents pour la même règle).
- Modification d'une règle : `DELETE` + `INSERT` pour son `exclusion_id`
  dans la **même transaction** → pas de fenêtre où l'article serait
  partiellement réconcilié.
- Collecte : l'insert des lignes `article_user_hidden` se fait dans la
  transaction `article_veille` existante (même verrou `FOR UPDATE` du
  Writer) → cohérence article/masquage atomique.
- `user_exclusions` : `UNIQUE(user_id, kind, pattern(191))` → rejette les
  doublons exacts par utilisateur ; `idx_exclusion` rend les `DELETE` de
  réconciliation O(lignes de la règle) et non O(table).
- Deux règles créées en concurrence pour le même article → deux lignes
  distinctes, aucune course (PK disjointe).

### 9.5 Limites assumées

- Réapparition après désactivation/suppression : seuls les articles encore
  présents dans `article_veille` de l'utilisateur (ou encore dans le
  référentiel pour un masquage non lié à une veille) redeviennent visibles.
- Règles modifiées fréquemment : le job de réconciliation est débouncé
  par `exclusion_id` (un seul job en vol par règle, verrou `Cache::lock`
  base de données).

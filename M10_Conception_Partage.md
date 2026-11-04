# M10 — Conception technique : partage privé des veilles en lecture seule

> Statut : **conception validée en revue** — aucune implémentation.
> Prérequis : M8 (connecteurs), M9 (exclusions personnelles) déployés.

## 1. Analyse de l'existant

### Mécanismes réutilisables

| Existant | Rôle M10 |
|---|---|
| `Veille::articles()` (BTM via `article_veille`) | Périmètre d'articles partagés |
| `VeilleArticleQuery::base()/filtered()` | Requêtes de lecture à adapter — elles appliquent déjà rétention + `ArticleVisibility` |
| `ArticleVisibility::apply($q, $userId, $col)` | Filtre des masquages du **propriétaire** sur les vues publiques |
| `HtmlContentSanitizer` | Sanitization contenu des articles publics — déjà prouvée |
| `throttle:*` (ex. `source-detection`) | Rate limiting des routes publiques |
| `Str::random` / `hash('sha256')` | Génération + hash de jeton |
| Blade `layouts/app` + styles pass 2 | Gabarit public allégé à dériver |

### Points d'attention identifiés

- `VeilleArticleQuery::base(Veille $veille)` est **privé** et scopé par la
  relation `articles()` de la veille : l'accès public doit passer par un
  point d'entrée dédié — **jamais** par les contrôleurs privés dont la
  Policy exige `auth`.
- Le pivot `article_veille` expose `matched_keyword` : les mots-clés du
  propriétaire **ne doivent pas** fuiter dans les vues publiques.
- `article_user` (lu, favori, note, catégorie, archive) : jamais joint,
  jamais rendu côté public.
- `user_exclusions`/`article_user_hidden` s'appliquent au **propriétaire**
  : ses articles masqués restent masqués dans le partage (cohérent : le
  propriétaire choisit de ne pas les montrer).

## 2. Modèle de données — `veille_shares`

| Colonne | Type | Détail |
|---|---|---|
| `id` | bigint auto | PK |
| `user_id` | bigint | FK → `users` `cascadeOnDelete` (partages liés au propriétaire) |
| `veille_id` | bigint NULL | FK → `veilles` `cascadeOnDelete` : **scope=single meurt avec sa veille** ; NULL pour scope=all |
| `scope` | varchar(8) | `'single'` ou `'all'` |
| `token_hash` | char(64) | SHA-256 hex du jeton — **UNIQUE** |
| `label` | varchar(120) NULL | Libellé interne du propriétaire |
| `is_active` | boolean | Toggle sans perte de configuration |
| `expires_at` | timestamp NULL | Expiration absolue (UTC) |
| `revoked_at` | timestamp NULL | Révocation datée (audit) |
| `rotated_at` | timestamp NULL | *(ajout M10.2)* dernière rotation — audit distinct de `revoked_at` |
| `created_at`/`updated_at` | timestamps | — |

### Contraintes et index

- `CHECK` MariaDB 10.11 (contrôle natif) :
  `(scope = 'single' AND veille_id IS NOT NULL) OR (scope = 'all' AND veille_id IS NULL)`.
- `UNIQUE(token_hash)` ; `INDEX(user_id)` (FK) ; `INDEX(veille_id)` (FK) ;
  `INDEX(is_active)` optionnel (faible cardinalité — non retenu).
- **Unicité des jetons** : SHA-256 de 32 octets aléatoires — collision
  impossible en pratique ; l'UNIQUE garantit le rejet au pire cas
  (regénération côté applicatif).
- **Partages multiples** : aucune contrainte d'unicité `(user_id, veille_id)`
  — un propriétaire peut générer plusieurs liens pour une même veille
  (ex. un par destinataire) avec labels distincts.
- **scope=all dynamique** : pas de `veille_id` — la liste est résolue à
  chaque requête sur `veilles.user_id` ; une veille supprimée disparaît
  automatiquement, une veille créée apparaît automatiquement.
- **Veilles inactives** : exclues de la liste globale (`is_active = true`)
  comme de la consultation — un partage single sur veille désactivée
  continue d'afficher ses articles (décision à trancher en M10.2 ;
  défaut retenu : visible tant que la veille existe).

### Rotation et révocation

- **Rotate** = regénération complète : nouveau jeton → nouveau hash
  **écrase** `token_hash` (l'ancien lien meurt immédiatement — pas de
  double validité documentée, plus simple et plus sûr).
- **Revoke** = `revoked_at = now()` + `is_active = false` (gardé pour
  audit) ; **Delete** = suppression de la ligne.
- **Desactivate** = `is_active = false` : suspension réversible.

## 3. Sécurité des jetons

- **Génération** : `random_bytes(32)` → encodage **base64url**
  (`rtrim(strtr(base64_encode($b), '+/', '-_'), '=')`) = 43 caractères
  [A-Za-z0-9_-] — sûr en path segment, aucune escape.
- **Stockage** : `hash('sha256', $token)` hex (64) en `token_hash`.
  Le jeton clair n'existe que dans la réponse à la création/rotation.
- **Lookup** : `veille_shares WHERE token_hash = hash AND is_active
  AND revoked_at IS NULL AND (expires_at IS NULL OR expires_at > now())`.
  Résolution par index unique ; `hash_equals` si comparaison applicative.
- **Échec** → **404** (pas 403) : ne pas distinguer « inexistant »,
  « expiré » et « révoqué » — aucune information sur l'existence.
- **Abus** : `throttle:60,1` sur les routes publiques + éventuel
  `429` Laravel natif ; pas de verrou par IP nécessaire au MVP.
- **Logs** : le jeton apparaît dans `access.log` Apache (URL) —
  inévitable et accepté ; **jamais** écrit dans `laravel.log` (aucun
  log applicatif des jetons en clair).
- **Headers publics** : `Cache-Control: no-store` + `X-Robots-Tag:
  noindex, nofollow` (robots.txt seul ne protège pas un lien connu).
- Le hash seul en base → impossible de retrouver le jeton même avec un
  dump (`SELECT` ou vol de sauvegarde).

## 4. Routes et composants

### Gestion propriétaire (groupe `auth` existant)

```
GET    /shares                  ShareController@index    shares.index
POST   /shares                  ShareController@store    shares.store
PATCH  /shares/{share}          ShareController@update   shares.update   (label, is_active, expires_at)
POST   /shares/{share}/rotate   ShareController@rotate   shares.rotate
DELETE /shares/{share}          ShareController@destroy  shares.destroy
```

`VeilleSharePolicy` (ou `own()` style M9.1) : 404 sur tout share
d'autrui. `StoreVeilleShareRequest` : scope ∈ {single, all},
`veille_id` requis + appartenant au user si single, label ≤ 120,
`expires_at` date future nullable.

### Consultation publique (hors groupe `auth`)

```
GET /shared/{token}                   PublicShareController@index      shared.index
GET /shared/{token}/veilles/{veille}  PublicShareController@veille     shared.veille
GET /shared/{token}/articles/{article} PublicShareController@article   shared.article
```

Middleware `ResolveShareToken` (sur les 3 routes + `throttle:60,1`) :
résout le share, **404 sinon**, injecte `share` dans la requête.
`{veille}`/`{article}` : résolution manuelle **bornée au périmètre**
(pas de route model binding global — `Veille::where('user_id',
$share->user_id)` + contrôle scope).

Nouveau `PublicShareQuery` (service) réutilisant la logique de
`VeilleArticleQuery::base` **dupliquée en version publique** plutôt que
d'ouvrir le privé : mêmes bornes (rétention, `ArticleVisibility` pour
`share.user_id`, tri date effective DESC + id DESC, pagination 20) —
pas de `matched_keyword`, pas de `source_id` filtre (simplification).

Vues `shares/*` : gabarit public minimal dérivé de l'identité pass 2
(pas de nav privée, pas de JS applicatif ; Alpine inutile côté public).

## 5. Confidentialité — règles impératives

1. Visiteur ⇒ **uniquement** `article_veille` des veilles du périmètre
   (single : la seule veille ; all : `veilles.user_id = share.user_id
   AND is_active`) — jamais `articles` seul ni autre utilisateur.
2. Champs rendus : titre, résumé/contenu sanitisé, source (nom +
   site_url), dates, nom de veille. **Jamais** : `matched_keyword`,
   `article_user` (lu/favori/note/catégorie/archive), `user_exclusions`,
   identité des autres utilisateurs, métadonnées internes
   (`dedup_key`, `config`, `fetch_url`…).
3. Masquages propriétaire (`article_user_hidden` actifs) appliqués via
   `ArticleVisibility` — un article masqué ne fuit pas.
4. Suppression de veille ⇒ disparaît du partage global (résolution
   dynamique) **et** rend le lien single mort (`cascadeOnDelete`).
5. Pas de pagination deep-linkable fragile : curseur `?page=` suffit
   (rétention naturelle borne l'exposition à 15 jours).
6. Aucun état d'authentification exigé ou exposé : les routes publiques
   ne créent ni session ni cookie (middleware `web` sans
   `auth` — CSRF inopérant sur GET de toute façon).
7. `no-store` + `noindex` systématiques ; liens internes publics
   toujours par jeton (jamais d'ID interne hors périmètre).

## 6. Interface propriétaire

Rubrique **« Partages »** (nav, à côté de « Mes exclusions ») :

- Liste : scope (icône veille/globe), cible (nom veille ou « Toutes
  mes veilles »), label, état (actif/expiré/révoqué), `expires_at`,
  lien copiable (affiché en clair uniquement — c'est l'URL, pas le
  secret de session), boutons toggle/rotate/supprimer.
- Création : radio scope → si single, `<select>` des veilles propres
  + label + expiration optionnelle (date).
- Le **jeton complet n'est jamais ré-affichable** (hash seul en base) :
  message à la création « copiez ce lien maintenant » ; rotate =
  nouveau lien remplaçant l'ancien.

## 7. Plan de développement

| Mission | Contenu |
|---|---|
| M10.2 | Migration `veille_shares` + modèle + `TokenService` (generate/hash/resolve) + tests unitaires jeton |
| M10.3 | CRUD propriétaire : contrôleur, request, policy, routes, vue gestion + nav + tests feature |
| M10.4 | `ResolveShareToken` + `PublicShareQuery` + `PublicShareController` + routes publiques + vues publiques + tests feature |
| M10.5 | Durcissement (throttle, headers, noindex), EXPLAIN perf, docs (DataModel/Architecture/REPRISE/Changelog), migration production |

Cas limites à couvrir : jeton tronqué/invalide → 404 ; expiré/révoqué
→ 404 ; single → autres veilles 404 ; global → veille supprimée hors
liste ; article hors périmètre → 404 ; rotate → ancien lien 404 ;
masquage propriétaire respecté ; aucune donnée `article_user` dans
le HTML rendu ; throttle 429.

## 8. Risques et limites

- Lien transmissible = accès total au périmètre (choix assumé : pas
  de mot de passe invité en MVP).
- `expires_at` non obligatoire — responsabilité propriétaire ;
  rotation conseillée documentée.
- Articles publics = état **courant** (pas d'historique au-delà de la
  rétention 15 j).
- Le partage n'expose jamais le fil d'un autre utilisateur : les
  articles mutualisés ne sont listés que via `article_veille` du
  propriétaire — conforme à l'isolation M9.

## 9. Audit M10.5 — garanties et risques résiduels

**Garanties implémentées** : `ResolveShareToken` pose `no-store`,
`noindex` et `Referrer-Policy: no-referrer` sur toutes les réponses,
y compris les 404 uniformes (texte simple — aucune distinction entre
inexistant/expiré/révoqué). Contrôleurs publics séparés, périmètre
revérifié à chaque requête, `ArticleVisibility` du propriétaire,
rétention 15 j, sanitizer HTML, liens `noopener noreferrer`, throttle
IP 60/min, index composite `article_veille(veille_id,user_id)` utilisé
(EXPLAIN). Aucune écriture visiteur (POST/DELETE → 405).

**Risque résiduel assumé** : le jeton figure dans le chemin d'URL →
les **journaux d'accès Apache/ISPConfig** (`~/log/access.log`) et
l'historique du navigateur le conservent. Non éliminable sans
changement d'architecture (jeton en fragment `#` invisible serveur
ou cookie — hors périmètre du choix lien-partage). Mitigations en
place : aucun log applicatif du jeton, aucune ressource externe sur
les pages partagées, `no-referrer` coupe la fuite vers tiers,
rotation/révocation/expiration bornent la fenêtre d'exposition.
À documenter à l'utilisateur : un lien partagé est un secret —
le transmettre c'est partager l'accès.

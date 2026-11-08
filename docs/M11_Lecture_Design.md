# M11 — Statut de lecture global : audit et conception

> Constat principal : **le statut de lecture est déjà global** par
> `(user_id, article_id)` dans la table `article_user` — aucune
> migration de données n'est nécessaire. M11 est une mission
> d'interfaces et de requêtes, pas de réconciliation de données.

## 1. Fonctionnement actuel (démontré)

- **`article_user`** : `user_id` + `article_id` + `is_read`,
  `is_favorite`, `is_archived`, `note`, `category_id` — **une seule
  ligne par (user, article)**, jamais par veille.
- **`ArticleReadState`** : `isRead` (absence de ligne OU `is_read=0` →
  non lu), `markRead` (upsert), `markUnread` (supprime la ligne si
  vide hors `is_read`, §3.9 — évite les lignes orphelines), `readMap`
  pour les listes sans N+1.
- **Routes existantes** : `PUT`/`DELETE
  `/veilles/{veille}/articles/{article}/read` — l'URL est scopée veille
  mais l'écriture est globale : **marquer lu dans une veille marque
  déjà lu partout** pour cet utilisateur.
- **`VeilleArticleQuery::base()`** : rétention **6 mois**
  (`VeilleRematcher::RETENTION_MONTHS` sur date effective) + exclusion
  archives + `ArticleVisibility` M9 — **pas de filtre lu/non lu**
  aujourd'hui. *(Précision M11.1 : la borne « 15 jours » figurant dans
  `PublicShareQuery` est propre au partage public M10 — fenêtre
  d'exposition choisie, incohérence à trancher en M10.5+.)*
- **`DashboardController`** : `unreadPerVeille` (leftJoin article_user,
  null ou false) + compteurs + `recents` = 10 derniers `article_veille`
  (lus **et** non lus mélangés).
- **Favoris/archives/catégories/notes** : même `article_user` —
  indépendants du statut de lecture par construction.
- **Partage M10** : `article_user` jamais joint ni rendu → aucune
  fuite d'état de lecture, vérifié par tests.
- **Exclusions M9** : `article_user_hidden` + jointure `is_active` —
  indépendantes et prioritaires.

## 2. Comportement cible

Le modèle `user_id + article_id` est déjà le bon : lu dans une veille
⇒ lu partout pour cet utilisateur ; isolation entre utilisateurs
garantie. **Lacunes purement d'interface/requêtes** :

- Pas de filtre Non lus/Tous/Lus sur la page d'une veille.
- Le dashboard affiche les articles lus dans « récents ».
- Pas d'action « tout marquer lu » par veille.

## 3. Architecture cible

- **Données** : aucun changement — `article_user.is_read` reste la
  source de vérité unique.
- **Requêtes** : ajout d'un filtre `state ∈ {unread, all, read}` sur
  `filtered()` via LEFT JOIN `article_user` (même pattern que
  `unreadPerVeille` — indexée, pas de N+1) :
  - `unread` : `article_user.is_read IS NULL OR is_read = 0` ;
  - `read` : `article_user.is_read = 1` ;
  - `all` : pas de condition.
- **Dashboard** : `recents` filtré non lus par défaut (+ compteur
  global cohérent) ; `unreadPerVeille` et les badges inchangés.
- **Actions** : `markRead`/`markUnread` existants ; nouvelle route
  `POST /veilles/{veille}/articles/read-all` + service de marquage
  massif. **Périmètre validé M11.1** : tous les articles **non lus**
  de la veille courante (toutes pages, rétention 6 mois incluse),
  hors articles masqués M9 ; aucun impact autres veilles/utilisateurs
  ni favoris/archives/notes/catégories ; confirmation explicite ;
  lots de 500 `insertOrIgnore` transactionnés (pas de `save()` en
  boucle).

## 4. Migration et conservation des données

**Aucune.** Le schéma `article_user` est déjà global :
- aucun doublon possible (PK user_id+article_id) ;
- aucun statut contradictoire (une seule ligne par couple) ;
- articles sans ligne = non lus (sémantique existante conservée) ;
- aucune perte, aucun traitement rétroactif.

## 5. Interfaces

- **Page veille** : onglets « Non lus (défaut) · Tous · Lus » —
  paramètre `?state=`, cumulable avec filtres source/mot-clé ;
  un article marqué lu disparaît immédiatement de « Non lus ».
- **Dashboard** : « récents » = non lus ; badges existants conservés.
- **Favoris/archives/catégories** : inchangés — l'état de lecture
  n'affecte pas leur contenu.
- **Actions** : toggle lu/non lu existant + bouton « Tout marquer lu »
  avec `confirm()` — style existant, pas de nouvelle lib.

## 6. Performances

- LEFT JOIN `article_user` sur `(user_id, article_id)` — index déjà
  utilisé par `unreadPerVeille` (vérifié M9.3).
- `readMap` conserve le batch par liste — pas de N+1.
- « Tout marquer lu » : `insertOrIgnore` par lots de 500 dans une
  transaction — borné au périmètre normal de la veille (rétention 6
  mois + exclusions M9 + non-archivés), pas de balayage illimité.
- Pagination inchangée (`paginate(20)`).

## 7. Tests

- marquage global : lu depuis veille A ⇒ non lu dans veille B aussi ;
- filtre unread/read/all par veille + combinaison filtres existants ;
- recents dashboard sans lus ;
- « tout marquer lu » : périmètre veille, exclusions respectées,
  idempotent ;
- isolation entre utilisateurs ;
- favoris/archives indépendants ;
- M10 inchangé (aucun état de lecture exposé).

## 8. Découpage

| Mission | Contenu |
|---|---|
| M11.2 | Filtre `state` dans `VeilleArticleQuery` + onglets page veille + tests |
| M11.3 | Dashboard : recents non lus + compteurs + tests |
| M11.4 | Action « tout marquer lu » + route + service + tests |
| M11.5 | Doc finale + déploiement (pas de migration — vérifications seules) |

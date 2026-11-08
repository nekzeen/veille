# M12.10 — Conception de la rétention et purge des articles

> Audit + conception uniquement. Aucune suppression implémentée —
> livrable pour validation.

## 1. Cartographie des dépendances `article_id`

| Table | Données | FK vers `articles` |
|---|---|---|
| `article_veille` | association veille↔article (par user) | `cascadeOnDelete` |
| `article_user` | lu/favori/archive/note/catégorie | `cascadeOnDelete` |
| `article_user_hidden` | masquage M9 | `cascadeOnDelete` |
| `veille_alerts` | `articles_count` + curseurs `article_veille.id` | **aucune FK** — bornes de fenêtre |

**Point critique** : supprimer un `articles` supprime en cascade ses
`article_user` (notes/favoris inclus) — interdit sans filtre.

`collected_at` est `NOT NULL` → `COALESCE(published_at, collected_at)`
**jamais NULL** — tout article est datable ; `published_at` NULL →
repli `collected_at`. Pas de cas « sans date » en pratique.

## 2. Comportement actuel

- Fenêtre **6 mois** appliquée aux requêtes (`VeilleArticleQuery`,
  `PublicShareQuery` 15 j — incohérence signalée M11.1) ;
- **aucune purge** : les lignes persistent indéfiniment — la taille
  de `articles`/`article_veille` croît sans borne.

## 3. Risques identifiés

| Cas | Risque |
|---|---|
| Article dans plusieurs veilles (même user) | supprimer une ligne `article_veille` à la fois — pas l'article |
| Article multi-utilisateurs | suppression du catalogue = état d'un tiers détruit (cascade `article_user`) |
| Article **avec note ou favori** | **NE JAMAIS supprimer l'article** si `article_user` le porte |
| Article avec exclusion | `article_user_hidden` cascade au même titre — pas de souci |
| Veille encore active | article > 6 mois retiré même si veille active — cohérent avec la fenêtre actuelle |
| Curseurs `veille_alerts` | pointent des `article_veille.id` supprimés — **sans danger** : borne de fenêtre, les IDs ne remontent pas |
| `articles` orphelins (0 association) | candidats naturels à la purge |

## 4. Politique recommandée

### Étape A — retrait des associations périmées (sûre)

`DELETE FROM article_veille WHERE (date effective) < now-6mo`
— supprime l'association (personnelle, par user) ; **laisse intactes**
`article_user`, `article_user_hidden`, `articles`.

- Laisse les notes/favoris accessibles via favoris/archives (vue
  indépendante de la veille) — conformément à « ne pas supprimer une
  note parce que l'association disparaît » ;
- Curseurs alertes : inertes, aucune perte.

### Étape B — purge du catalogue (avec garde-fou)

Supprimer un `articles` **uniquement si** :
1. aucune ligne `article_veille` ne le référence ;
2. aucune ligne `article_user` avec `note`, `is_favorite=1` ou
   `is_archived=1` ne le référence (le simple `is_read` peut partir —
   ou tout `article_user` restant, à trancher — voir §6) ;
3. dans une transaction, lots de 500, `NOT EXISTS` sur les deux.

Conséquence acceptée : suppression de l'article → cascade efface
`article_user`/`article_user_hidden` restants (états de lecture
orphelins — souhaitable).

## 5. Algorithme proposé

```php
// A — associations périmées (lots)
$ids = ArticleVeille::join articles
    ->whereRaw(EFFECTIVE_DATE.' < ?', [$cutoff])
    ->pluck('article_veille.id')
    ->chunk(500) → ArticleVeille::whereIn('id')->delete();
// B — catalogue (lots + NOT EXISTS)
Article::whereDoesntHave('articleVeilles')
    ->whereDoesntHave('articleUsers', fn($q)=>$q->where('is_favorite',1)->orWhereNotNull('note')->orWhere('is_archived',1))
    ->chunkById(500, delete) — transaction par lot.
```

- `dry-run` : `--dry-run` affiche les comptes sans rien écrire ;
- log des lots supprimés (`laravel.log` ou table `purge_log` — à trancher) ;
- fréquence : **hebdomadaire** suffit (jour creux, ex. dimanche 03:30).

## 6. Décisions requises

1. Sort des `article_user` sans note/favori/archive (is_read seul) :
   supprimés avec l'article (recommandé) ou purge indépendante ?
2. Délai entre A et B : immédiat ou décalage de sécurité (ex. 24 h) ?
3. Articles orphelins jamais associés : purger aussi (recommandé) ?
4. Traçabilité : `laravel.log` ou table dédiée `purge_log` ?
5. Heure exacte du cron hebdomadaire.

## 7. Tests prévus (post-validation)

- article < 6 mois : inchangé ; > 6 mois : `article_veille` supprimé,
  `article_user` favori conservé (article gardé) ;
- article multi-utilisateurs : suppression d'une veille ne touche pas
  l'autre user ;
- article orphelin sans données → supprimé ; avec note → conservé ;
- curseurs alertes : aucun crash, aucune re-notification ;
- dry-run ne supprime rien ; lots > 500 OK ; transaction = pas de
  partiel.

## 8. Découpage

M12.11 : commande `articles:purge` (dry-run + A+B + logs) → M12.12 :
planification + déploiement — après validation des §6.

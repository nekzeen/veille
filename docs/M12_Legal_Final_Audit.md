# M12.5 — Audit technique final et préparation juridique évolutive

> Audit factuel avant rédaction des `/legal`, `/terms`, `/privacy`.
> Chaque affirmation est classée : **actif aujourd'hui** / **techniquement
> présent mais non activé** / **futur envisagé**. Aucun texte juridique
> définitif n'est rédigé — les trous sont listés pour l'éditeur.

## 1. Traitements réellement actifs aujourd'hui

| Traitement | Données | Fait observé |
|---|---|---|
| Compte & auth | name, email, hash bcrypt, `email_verified_at`, `remember_token` | `users` — Laravel Breeze |
| Session | IP, user-agent, payload | `sessions` (driver `database`), durée 120 min, purge probabiliste `lottery [2,100]` |
| Veilles personnelles | mots-clés, sources, `article_veille` | `veilles`, `veille_source`, `article_veille` |
| États personnels | lu/favori/archivé/note/catégorie | `article_user` |
| Exclusions | patterns url/domaine/mot-clé | `user_exclusions` + `article_user_hidden` |
| Partages | jeton SHA-256, scope, expiration | `veille_shares` (jamais de jeton en clair) |
| Digests/alertes | brouillons d'e-mails | `digest_drafts` + commandes `alerts:*` → **Postfix local** |
| Queue | payloads de jobs | `jobs` (driver database) |
| Consentement M12.4 | `terms_version`, `terms_accepted_at`, `privacy_version` | colonnes nullable créées (migration en attente de prod) |

## 2. Présent techniquement mais NON activé

- **Outils de stats** : aucun (déclaré par l'éditeur, vérifié : aucun
  script externe dans les vues — ressources = Vite local + Bunny Fonts
  `fonts.bunny.net` dans `guest`/`app` layouts, **ressource tierce
  chargée — voir §4**).
- **Cookies publicitaires/traceurs** : aucun ; cookies = session +
  XSRF strictement.
- **Services externes** : aucun autre que Bunny Fonts (police).
- **Consentement actif** : colonnes présentes mais `RegisteredUserController`
  ne les remplit pas encore (versions `draft-0` provisoires).

## 3. Rétentions — configuré vs observé

| Donnée | Configuré | Observé | Cible à décider |
|---|---|---|---|
| Sessions | 120 min | 120 min + purge 2 % | OK, documenter |
| Articles affichés | 6 mois (`RETENTION_MONTHS`) | 6 mois de fenêtre ; **lignes persistantes sans purge automatique** | définir une purge réelle ou documenter la conservation indéfinie |
| Partages publics | 15 j d'exposition (`PublicShareQuery`) + `expires_at`/`revoked_at` | fonctionnel | OK |
| Sauvegardes `private/backups/veille/` | aucune rotation | ~10 répertoires manuels | **définir une rétention** (ex. 7 j) — contiennent la DB complète |
| `laravel.log` | rotation `daily` | aucune donnée personnelle applicative | OK |
| `access.log`/`error.log` (`~/log`) | hors app | IP + URL complètes (jetons de partage inclus — résiduel M10.5) | hors périmètre applicatif ; à documenter |
| Postfix logs | serveur | hors contrôle applicatif | documenter comme sous-traitance |

## 4. Ressource tierce à déclarer

**Bunny Fonts** (`fonts.bunny.net/css?family=figtree`) chargée dans
`app`+`guest` layouts → le navigateur du visiteur envoie son IP au CDN
Bunny Fonts (Allemagne, UE). **Seule ressource externe** — doit figurer
dans la politique ou être remplacée par une police auto-hébergée.

## 5. Hébergement et transferts

- IP `51.255.87.180` = plage **OVH** (serveur dédié France) →
  hébergeur = sous-traitant technique UE. **L'entité contractante
  exacte (OVH SAS/Cloud) est à confirmer par vous — je ne la déduis pas.**
- E-mail : sendmail → **Postfix local**, aucun prestataire externe.
- Transferts hors UE : **aucun identifié côté code**, mais non-attestable
  sans votre confirmation (sous-traitants OVH, sauvegardes localisées).

## 6. Partage M10 — exposition résiduelle

- Jeton SHA-256 hashé en base, jamais en clair ; URL `/shared/{token}`
  dans `access.log` (IP + jeton) — **correction possible** : Apache
  `LogFormat` tronqué ou `/shared/` via POST/cookie — hors périmètre,
  non modifiée ici.
- Headers `no-store`/`noindex`/`no-referrer` en place ; Referer-Policy
  coupe la fuite sortante.

## 7. Suppression de compte (pour M12.6 — pas encore implémentée)

Relations à traiter à la suppression d'un `users` :
`veilles` → `article_veille`, `veille_source` (pivot) ;
`article_user`, `user_exclusions`, `article_user_hidden`,
`veille_shares`, `digest_drafts`, `sessions` (FK `user_id`) ;
jobs en attente éventuels.
`articles`/`sources` = catalogue commun, **jamais** supprimés.
Proposition : route `POST /profile/delete` Breeze existante +
cascade FK (`article_user`, `veille_shares`, `user_exclusions`
cascadent déjà si FK correctes — à vérifier en M12.6) + purge
`sessions`/`article_user_hidden` + **preuves de consentement
conservées** (RGPD : preuve contractuelle, pas une donnée de traitement
— ou pseudonymisée).
Délai technique : sauvegardes purgées sur rotation future.

## 8. Évolutions futures — mécanisme (pas de consentement générique)

Pour toute ajout futur (Matomo/GA, traceurs, services externes,
prestataire e-mail, payant) : audit préalable → fournisseur+rôle →
données transmises → base juridique → transferts UE → durée → mise à
jour de la politique → consentement si requis → information
préalable. **L'acceptation des CGU n'ouvre aucun droit automatique.**

## 9. Pages juridiques — structure proposée

| Page | Contenu |
|---|---|
| `/legal` | Éditeur personne physique (nom, adresse, contact — **à fournir**), hébergeur OVH |
| `/terms` | Objet, accès gratuit, compte, CGU version affichée — projet personnel non pro |
| `/privacy` | Données réelles §1, durées §3, sous-traitants §5, droits RGPD, suppression compte, évolution §8 |

Routes publiques `GET`, guest layout, pas de compte requis ; versions
`LEGAL_*` affichées ; liens footer + register + login.

## 10. À fournir par l'éditeur

1. Identité complète (nom, adresse postale, e-mail de contact) ;
2. Entité OVH contractante exacte ;
3. Rétention cible des sauvegardes ;
4. Politique de suppression de compte (immédiat vs délai) ;
5. Validation des contenus définitifs des 3 pages.

## 11. Découpage

| Mission | Contenu |
|---|---|
| M12.6 | Suppression de compte (route + cascade + preuves) — après décision §7 |
| M12.7 | Pages `/legal` `/terms` `/privacy` (structure + contenu final à fournir) + liens + versions actives dans `RegisteredUserController` |
| M12.8 | Déploiement migration `000006` + activation consentement |

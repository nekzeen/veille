# M12.3 — Pages juridiques et traçabilité du consentement

> Audit factuel + conception. Aucune migration ni implémentation —
> livrable pour validation uniquement. **Aucun contenu juridique
> n'est inventé** : chaque manque est listé pour Gaël.

## 1. État démontré (fait, non supposé)

- `POST /register` exige déjà `consent => required|accepted` (M12.2) —
  **non persisté** : la case est cochée puis jetée, aucune preuve.
- Table `users` : `name`, `email`, `password` (bcrypt), `email_verified_at`,
  `remember_token`, timestamps — **pas** de colonne de consentement.
- Session driver `database` → table `sessions` stocke `user_id`,
  `ip_address`, `user_agent`, `payload`, `last_activity`.
- Données personnelles métier : `veilles`, `article_veille`,
  `article_user` (lu/favori/archivé/note/catégorie), `user_exclusions`,
  `article_user_hidden`, `veille_shares` (lien public → accès anonyme
  aux articles partagés), digests `DigestDraft` destinés à l'e-mail.
- Rétentions observées : articles consultables 6 mois
  (`RETENTION_MONTHS`) ; partage public 15 j (`PublicShareQuery`) ;
  aucune purge automatique des comptes ni des états `article_user`.
- Journaux : `laravel.log` ne reçoit aucune donnée personnelle
  applicative ; **`access.log` Apache enregistre IP + URL complètes
  (jetons de partage inclus — risque résiduel documenté M10.5)** ;
  logs Postfix hors contrôle applicatif.
- Cookies : session + XSRF standard Laravel ; aucun cookie tiers ni
  outil de mesure.
- Aucune page `/terms`, `/privacy`, `/legal` — vérifié routes+vues.

## 2. Traçabilité du consentement — proposition

### Modèle retenu : colonnes sur `users` (le plus simple et cohérent)

```
terms_version      string, nullable — ex. '2026-11'
privacy_version    string, nullable
consent_given_at   timestamp, nullable
```

- `nullable` : comptes existants = jamais consenti explicitement →
  **pas de consentement artificiel**, conformément à la contrainte.
- Version des documents : constante unique (config) incrémentée à
  chaque modification → pas de table `legal_documents` (YAGNI).
- Alternative étudiée et écartée : table `user_consents` (historique
  multiple) — aucun besoin de suivi des versions antérieures à ce
  stade ; à réouvrir seulement si re-consentement exigé un jour.
- **Ne pas confondre** : `consent` = acceptation des CGU
  (contrat d'utilisation) ; la politique de confidentialité est une
  **information fournie**, pas un consentement générique — le texte
  de la case M12.2 devra être séparé en deux formulations lors de la
  livraison des vrais documents.

### Migration proposée (non créée — pour validation)

`2026_xx_xx_add_consent_fields_to_users_table.php` :
`->string('terms_version')->nullable()`, `->string('privacy_version')->nullable()`,
`->timestamp('consent_given_at')->nullable()` — réversible `down()` drop
columns, compatible MariaDB 10.11.

## 3. Routes et vues à créer

- `GET /terms` → `pages/terms.blade.php`, `GET /privacy` →
  `pages/privacy.blade.php` — invité seulement (route guest, publique).
- Layout `x-guest-layout` existant + logo Veille — même identité.
- Corps : composant `@include('legal._placeholder')` clairement
  marqué « Contenu en cours de rédaction — ne pas traiter comme
  document contractuel ». Aucun faux texte.
- Lien formulaire register : `consent` devient
  « J'accepte les [conditions d'utilisation](/terms) et j'ai lu la
  [politique de confidentialité](/privacy) » — texte adapté quand les
  documents existeront ; la case reste fonctionnelle sans eux.
- `/legal` (mentions légales) : **à trancher** — nécessaire si le site
  doit afficher un éditeur identifié (recommandé, contenu = vous).

## 4. Informations que vous devez fournir

1. Identité/coordonnées du responsable de traitement (éditeur) ;
2. Finalités : veille personnalisée, alertes e-mail, partage
   facultatif — à confirmer/formuler ;
3. Durées de conservation **réelles** : sessions (120 min par défaut
   Laravel), articles 6 mois, partages expirés, compte supprimé = ? ;
4. **Politique de suppression de compte** — inexistante aujourd'hui :
   voulez-vous un delete cascade ? anonymisation ? délai ?
5. Sous-traitants : hébergeur (serveur dédié ? ISPConfig), sendmail
   local Postfix, aucun tiers externe — à confirmer ;
6. Transferts hors UE : aucun identifié — à attester ;
7. Partage public : mode d'accès (lien secret), données exposées
   (titre/résumé/article, pas les états utilisateur) — pour la
   rédaction.

## 5. Tests prévus (post-validation)

- `GET /terms`, `/privacy` → 200 public, aucune fuite privée ;
- inscription avec `consent` → `terms_version`, `privacy_version`,
  `consent_given_at` écrits ;
- compte existant → colonnes NULL (pas de consentement inventé) ;
- re-inscription refusée (email unique) ne touche pas les preuves
  antérieures ;
- version = constante de config contrôlée ;
- SQLite + MariaDB `veille_test`, pint, phpstan, diff --check.

## 6. Découpage proposé

| Mission | Contenu |
|---|---|
| M12.4 | Migration `users` (3 colonnes nullable) + `Registered` + version config — après validation de cette conception |
| M12.5 | `/terms` + `/privacy` (vues placeholder claires + routes publiques) + liens register |
| M12.6 | `/legal` (éditeur) + politique de suppression de compte — décision requise §4.4 |

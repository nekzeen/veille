# M12 — Checklist de validation avant publication légale

## Faits techniques vérifiés ✅

- Figtree auto-hébergée, **0 référence Bunny Fonts** — plus aucun
  appel externe de police (M12.8).
- Hébergeur : IP `51.255.87.180` = plage **OVH** (dédié France).
- Sessions : `SESSION_LIFETIME=120` + purge probabiliste `[2,100]`.
- Articles : fenêtre 6 mois affichée, **lignes persistantes**.
- Sauvegarde auto `backup-veille.sh` : rotation 7 j **codée et testée**.
- Partages : SHA-256 hashés, headers `no-store/noindex/no-referrer`.
- Suppression de compte : cascade + sessions (M12.6, testée).
- Consentement : 3 colonnes `users` (nullable) + `config/legal.php` —
  **non actives** (`draft-0`, contrôleur non branché).

## Points juridiques à confirmer ⚠

1. **LCEN art. 6, III, 2** : condition « informations transmises
   à l'hébergeur » — vérifier auprès d'OVH qu'elle est satisfaite
   avant de ne publier ni adresse ni téléphone dans `/legal`.
2. **Base juridique** de la conservation des preuves de consentement
   (obligation contractuelle vs intérêt légitime) — à trancher.
3. **Durée de conservation des sauvegardes manuelles** — indéfinie
   actuellement ; fixation d'une limite à décider avant `/privacy`.
4. **Durée des logs Apache** — inconnue ; vérifier la config
   ISPConfig ou la politique OVH, sinon mentionner « hors périmètre ».
5. **Purge effective des articles** — décider si une purge réelle
   doit exister (aujourd'hui = persistance indéfinie en base).
6. Rédaction finale relue par vous (recommandation : revue juridique
   externe si usage public réel).

## Incohérences connues 🔍

| Texte | Réalité |
|---|---|
| « rétention 7 j » | **Pas encore active** — cron ISPConfig à configurer |
| « 6 mois » | fenêtre affichée, pas suppression en base |
| « 120 min » | durée config, nettoyage probabiliste non garanti |
| Partages M10 | `access.log` Apache contient IP + jetons — résiduel assumé |
| « Aucune donnée ne quitte le serveur » | **Faux** — liens externes, flux collectés, sources tierces |

## Décisions requises de l'éditeur

- [ ] Confirmer la transparence LCEN (adresse/téléphone privés OK) ;
- [ ] Activer le cron ISPConfig `0 4 * * *` pour `backup-veille.sh` ;
- [ ] Fixer la rétention des sauvegardes manuelles (7 j ? 30 j ?) ;
- [ ] Choisir la politique de purge articles (conserver indéfiniment
      ou purger > 6 mois) ;
- [ ] Décider si les preuves de consentement sont conservées après
      suppression de compte (non implémenté actuellement — à trancher) ;
- [ ] Valider les 3 textes `M12_*_Draft.md` ;
- [ ] Activer `LEGAL_*` et créer routes/vues après validation.

## Éléments techniques en conformité 🔧

- [x] Figtree auto-hébergée, licence incluse ;
- [x] Rotation sauvegardes automatiques 7 j (script + tests) ;
- [x] Suppression compte complète (cascade + sessions) ;
- [x] Jobs robustes à la suppression utilisateur (early-return) ;
- [ ] Cron de rotation activé en production ;
- [ ] Rétention sauvegardes manuelles documentée et appliquée ;
- [ ] Routes `/legal` `/terms` `/privacy` + liens footer ;
- [ ] `LEGAL_*` actifs dans `RegisteredUserController`.

## Interdiction absolue

Ne pas publier les pages tant que tous les points ⚠ non résolus ne
sont pas tranchés et les textes relus — `draft-0` ≠ conformité.

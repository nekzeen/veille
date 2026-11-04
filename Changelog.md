
## M9 — Exclusions personnelles (2026-11)

- CRUD « Mes exclusions » (UI + routes + policies).
- Moteur ExclusionMatcher (URL résolues, expressions accent-insensitive).
- Intégration collecte (ArticleVeilleWriter) + filtre lecture
  (ArticleVisibility) + digest.
- ApplyExclusionJob rétroactif + réconciliation + réactivation.
- Tests : 41 tests exclusions — suites vertes SQLite + MariaDB.

## M10 — Partage privé des veilles (2026-11)

- Table veille_shares + jetons SHA-256 (rotation, révocation, expiration).
- Gestion propriétaire « Mes partages » (CRUD + préselection par veille).
- Consultation publique /shared/{token} : lecture seule, périmètre
  borné, exclusions du propriétaire respectées, headers anti-indexation
  et no-referrer, throttle 60/min.
- Audit M10.5 : risque résiduel documenté (jeton dans access.log).

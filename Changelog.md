
## M9 — Exclusions personnelles (2026-11)

- CRUD « Mes exclusions » (UI + routes + policies).
- Moteur ExclusionMatcher (URL résolues, expressions accent-insensitive).
- Intégration collecte (ArticleVeilleWriter) + filtre lecture
  (ArticleVisibility) + digest.
- ApplyExclusionJob rétroactif + réconciliation + réactivation.
- Tests : 41 tests exclusions — suites vertes SQLite + MariaDB.

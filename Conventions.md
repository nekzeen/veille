# Conventions

## Langues

- **Documentation** : français (ce dépôt).
- **Identifiants et code** : anglais (`VeilleController`, `FetchFeedJob`,
  `KeywordMatcher`…).
- **Commentaires de code** : anglais, sobres, uniquement quand le code n'est
  pas auto-explicatif.
- **Commits** : Conventional Commits, sujet en anglais, corps libre (français
  accepté).

## Style de code PHP

- **PSR-12** appliqué par **Laravel Pint** (config par défaut).
- **Larastan** niveau ≥ 5 cible en M1 ; monter progressivement.
- Typage strict : `declare(strict_types=1)` en tête de fichier, types
  paramètres/retour systématiques, propriétés typées.
- Early returns préférés à l'imbrication ; pas de `else` après un `return`.
- Classes `final` par défaut sauf nécessité d'héritage.

### Organisation

- Contrôleurs **minces** → logique dans `app/Actions` (un cas d'usage par
  classe, méthode `handle()`/`execute()`) et `app/Services` (domaine
  réutilisable : `FeedFetcher`, `KeywordMatcher`).
- Validation : **FormRequest** dédié par endpoint avec entrée utilisateur.
- Accès : **Policy** par modèle appartenant à un utilisateur ; jamais de
  vérification d'appartenance ad hoc dans un contrôleur.
- Enums PHP 8.3 pour les listes de valeurs (`AlertFrequency`, `SourceStatus`).
- Noms de tables : pluriel snake_case ; pivots : alphabétique singulier
  (`item_veille`, `item_user`, `source_veille`).

## Frontend

- Blade pour la structure ; **Alpine.js** pour l'interactivité locale
  (toggles, édition inline, dropdowns) — pas de framework SPA.
- **Tailwind CSS** ; utilitaires en priorité, composants Blade extraits dès
  qu'un motif se répète (pas de CSS custom sauf impossibilité utilitaire).
- Accessibilité minimale : contrastes, focus visibles, `aria-label` sur les
  boutons icônes, navigation clavier sur les composants interactifs.

## Base de données

- Une **migration** par changement de schéma, réversible quand c'est possible
  (`down()` implémenté).
- Nommage : `create_items_table`, `add_alert_frequency_to_veilles_table`.
- Index explicites sur les colonnes de jointure/filtrage fréquent.
- Pas de requête SQL brute si Eloquent/Query Builder suffit.
- Factories + seeders pour les données de démonstration (catalogue de
  sources notamment).

## Tests

- **Pest** (à confirmer en M1 — PHPUnit natif en repli), structure
  `tests/Feature` (cas utilisateur) + `tests/Unit` (Services, Actions,
  Matcher).
- Nommage : `it('…')` décrivant le comportement attendu.
- SQLite en mémoire — aucune base dédiée à créer.
- Mock systématique des appels HTTP sortants (fetch de flux) —
  `Http::fake()` ou fixtures de flux en `tests/fixtures/`.
- Règle : tout bug corrigé s'accompagne d'un test qui le reproduit.
- Chemins critiques exigeant couverture : auth, policies/isolation,
  parsing SimplePie, matching mots-clés, dédoublonnage, alertes.

## Git

- `main` protégée par convention : déployée/déployable à tout moment.
- Branches : `feature/<sujet>`, `fix/<sujet>`, `chore/<sujet>` —
  courtes, fusionnées par PR.
- **Conventional Commits** : `feat:`, `fix:`, `docs:`, `refactor:`,
  `test:`, `chore:`, `perf:`, `build:`, `ci:`.
- Sujet ≤ 72 caractères, impératif ("add", pas "added").
- Tags semver `vX.Y.Z` sur les jalons de roadmap.

### Workflow de commits

- **Un commit indépendant par étape cohérente et validée** — ne jamais
  accumuler plusieurs fonctionnalités ou missions dans un même commit, et
  ne jamais attendre la fin de plusieurs missions pour versionner.
- Avant chaque commit : tests et contrôles qualité (`pint --test`,
  `phpstan`, `php artisan test`), examen des modifications (`git diff`),
  vérification des problèmes de format (`git diff --check`) et des fichiers
  effectivement indexés (`git diff --cached`).
- Ne jamais versionner de secrets, mots de passe, `.env`, clés privées,
  `vendor/`, `node_modules/` ou `public/build/` (artefacts).
- Pas de force-push sur `main`.
- Après chaque commit : communiquer son hash, son message et le résultat
  des contrôles.
- **Jamais de push sans demande ou validation explicite de Gaël.**

## Documentation

- Les 8 documents racine sont la source de vérité ; toute décision qui les
  contredit exige leur mise à jour **dans le même commit** que le code.
- Les ADR (Architecture Decision Records) courts peuvent être ajoutés dans
  `docs/adr/` si une décision post-M0 s'avère nécessaire.

## Sécurité (rappels opérationnels)

- Jamais de donnée sensible en clair dans les logs (`Log::` sans secrets).
- HTML des flux assaini avant affichage (purifier).
- URLs de flux : schéma `http(s)` uniquement + rejet des IP
  privées/réservées (anti-SSRF).
- `composer audit` / `npm audit` avant chaque mise en production ;
  dépendances récentes < 7 jours évitées.

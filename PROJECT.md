# Projet Veille — Vision et périmètre

## Vision

Une plateforme de veille informationnelle multi-utilisateurs, inspirée
fonctionnellement d'Inoreader. Chaque utilisateur dispose d'un **espace
personnel privé et autonome** dans lequel il organise sa propre veille.

Le service collecte les flux RSS/Atom de manière mutualisée lorsque plusieurs
utilisateurs suivent la même source, mais les préférences, les résultats, les
favoris et les notifications restent strictement individualisés.

## Fonctionnalités

### Périmètre MVP

- Compte utilisateur (inscription, connexion, mot de passe oublié).
- **Veilles personnelles** : nom + mots-clés + sélection de sources.
- **Sources** : catalogue de sources proposées + sources personnelles
  ajoutées par URL de flux.
- **Lecture** : liste des articles correspondant à chaque veille,
  marquage lu/non lu.
- **Favoris, notes, catégories** : organisation personnelle des articles.
- **Alertes e-mail** : envoi périodique des nouveaux articles d'une veille.

### Hors périmètre MVP (évolutions possibles)

- Partage d'articles ou de collections entre utilisateurs (espaces de partage
  **facultatifs et indépendants**, jamais obligatoires).
- Import/export OPML.
- Recherche plein texte sur les articles.
- API publique (Sanctum) et application mobile.
- Filtres avancés (expressions régulières, exclusions, opérateurs booléens).
- Suggestion automatique de sources.
- Interface "unread inbox" globale façon Inoreader.

## Règles métier

1. **Isolation** : un utilisateur ne voit que ses veilles, ses sources
   personnelles, ses favoris, ses notes et ses catégories. Aucune donnée
   personnelle n'est visible par un autre utilisateur.
2. **Mutualisation technique, pas fonctionnelle** : le même flux n'est
   téléchargé qu'une fois pour tous les utilisateurs, mais chacun a sa propre
   vue (lu/non lu, favoris, notes) sur les articles.
3. **Aucune logique pédagogique** : pas de rôles formateur/apprenant, pas de
   groupes de formation, pas de suivi pédagogique. Ce n'est pas une plateforme
   éducative.
4. **Partage facultatif** : si le partage est ajouté plus tard, il repose sur
   une action explicite de l'utilisateur et reste indépendant de son espace
   privé.
5. **Simplicité d'abord** : le MVP privilégie la fiabilité et la
   maintenabilité à la richesse fonctionnelle.

## Glossaire

| Terme | Définition |
|---|---|
| **Source** | Un flux RSS/Atom (URL), proposé dans le catalogue ou ajouté par un utilisateur. |
| **Article** (item) | Une entrée d'un flux : titre, lien, auteur, date, résumé/contenu. |
| **Veille** | Un objet appartenant à un utilisateur : nom + mots-clés + sources. |
| **Mot-clé** | Un terme recherché dans le titre/résumé des articles d'une veille. |
| **Favori** | Un article marqué par un utilisateur. |
| **Note** | Un texte libre attaché par un utilisateur à un article. |
| **Catégorie** | Un classement personnel (veilles et/ou favoris). |
| **Alerte** | Un e-mail périodique listant les nouveaux articles d'une veille. |
| **Catalogue** | L'ensemble des sources proposées à tous les utilisateurs. |

## Acteurs

- **Utilisateur** : rôle unique au MVP. Gère son espace privé.
- **Administrateur** (minimal) : maintenance du catalogue de sources proposées.
  Pas de gestion des contenus utilisateurs.

## Contraintes de conception

- Le développement se fait **directement sur le serveur** par SSH ; le dépôt
  Git du serveur est la copie de travail principale, synchronisée avec GitHub.
- Aucune modification du vhost Apache généré par ISPConfig.
- Aucun service Node.js en production.
- Voir [Architecture.md](Architecture.md) pour les choix techniques et
  [Conventions.md](Conventions.md) pour les règles de développement.

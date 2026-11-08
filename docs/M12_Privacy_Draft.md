# Politique de confidentialité — projet (M12.9)

> ⚠ **Projet interne à relire** — version `draft-0`. Basé sur l'audit
> technique `M12_Legal_Final_Audit.md`. Aucune affirmation non vérifiée.

## 1. Responsable de traitement

Gaël Morvan, personne physique — `contact@gaelmorvan.fr`.
Pas de DPO désigné (activité personnelle, non obligatoire).

## 2. Données traitées et finalités

| Données | Finalité | Base juridique proposée * |
|---|---|---|
| Nom, adresse e-mail, mot de passe haché | Compte, authentification, vérification e-mail | Exécution du service (art. 6.1.b) |
| Sessions (IP, agent utilisateur, jeton) | Session sécurisée, sécurité technique | Intérêt légitime (art. 6.1.f) |
| Veilles, mots-clés, sources personnelles | Fonction du service | Exécution (6.1.b) |
| États de lecture, favoris, archives, notes, catégories | Personnalisation | Exécution (6.1.b) |
| Règles d'exclusion | Filtrage personnel | Exécution (6.1.b) |
| Liens de partage (hash, paramètres, expiration) | Fonction de partage facultative | Exécution (6.1.b) |
| Digests d'alertes | Envoi e-mail demandé | Exécution (6.1.b) |
| Journaux serveur (IP, URLs) | Sécurité, disponibilité | Intérêt légitime (6.1.f) |
| Acceptation des CGU (version, date) | Preuve contractuelle | Obligation contractuelle / intérêt légitime — **à valider** |

\* Proposition de classification — validation juridique recommandée ;
**aucun traitement n'est fondé sur un consentement générique**.

## 3. Conservation — faits réels

- **Articles** : fenêtre de visibilité de **6 mois** ; les lignes en
  base ne sont **pas automatiquement supprimées** — la conservation
  effective est actuellement indéfinie (à décider).
- **Sessions** : durée configurée de **120 minutes** avec nettoyage
  probabiliste — la durée physique de conservation n'est pas
  strictement garantie.
- **Sauvegardes automatiques** (`backups/veille-auto/`) : rétention
  cible de **7 jours** — planification à activer et vérifier avant
  publication.
- **Sauvegardes manuelles historiques** (`backups/veille/`) :
  exception temporaire validée, **durée indéfinie**, pas de
  suppression automatique — réexamen requis avant publication
  définitive de cette politique.
- **Suppression de compte** : suppression immédiate des données
  actives (veilles, états, notes, exclusions, partages, sessions) ;
  les sauvegardes existantes peuvent encore contenir des copies
  jusqu'à leur destruction — la rétention des sauvegardes fixe de
  facto la durée rémanente.
- **Partages** : expirés, révoqués ou invalidés → accès impossible.
- **Journaux Apache** : contiennent IP et URLs complètes —
  y compris les jetons de partage ; leur durée de conservation
  n'est **pas connue** (hors contrôle applicatif, configuration
  hébergeur).

## 4. Destinataires et sous-traitants

| Service | Rôle | Localisation |
|---|---|---|
| **OVH SAS** | Hébergement serveur | France (UE) |
| **Postfix** | Envoi e-mail local | Serveur même — aucun tiers |
| ~~Bunny Fonts~~ | **retiré M12.8** — Figtree auto-hébergée | — |

- **Aucun** outil d'analyse d'audience, publicité ou traceur
  actuellement utilisé.
- Cookies : session + jeton CSRF uniquement, strictement nécessaires.
- **Attention** : les liens et sources externes consultés par le
  visiteur (articles originaux, flux collectés) impliquent des
  échanges avec des serveurs tiers hors de notre contrôle — nous ne
  prétendons pas qu'aucune donnée ne quitte le serveur.

## 5. Droits

Vous disposez des droits : d'accès, de rectification, d'effacement,
de limitation, d'opposition et de portabilité lorsqu'applicables —
exercables via `contact@gaelmorvan.fr`. Vous pouvez saisir la
**CNIL** (`cnil.fr`) en cas de litige.

## 6. Sécurité

Hashage bcrypt des mots de passe, jetons de partage hashés (SHA-256),
HTTPS, accès base restreint au serveur, sauvegardes chiffrées par
l'infrastructure système — *mesures déclarées, audit externe
recommandé si exigé*.

## 7. Évolutions

Cette politique sera mise à jour avant toute activation de nouveaux
traitements (mesure d'audience, services externes, fonctionnalités
payantes) — aucun consentement générique n'est demandé à l'avance.
